<?php

/**
 * A service interface to functionality related to getting and setting
 * permission information for nodes
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class PermissionService {
	
	public function __construct() {
	}
	
	/**
	 * 
	 * Allow this service to be accessed from the web
	 *
	 * @return array
	 */
	public function webEnabledMethods() {
		return array(
			'grant'					=> 'POST',
			'checkPerm'				=> 'GET',
			'getPermissionsFor'		=> 'GET',
		);
	}

	/**
	 * @var Zend_Cache_Core 
	 */
	protected $cache;

	/**
	 *
	 * @return Zend_Cache_Core
	 */
	public function getCache() {
		if (!$this->cache) {
			$this->cache = SS_Cache::factory('restricted_perms', 'Output', array('automatic_serialization' => true));
		}
		return $this->cache;
	}

	
	public function getAllRoles() {
		return DataObject::get('AccessRole');
	}
	
	public function getPermissionDetails() {
		return array(
			'roles'				=> $this->getAllRoles(),
			'permissions'		=> $this->allPermissions(),
			'users'				=> DataObject::get('Member'),
			'groups'			=> DataObject::get('Group')
		);
	}

	protected $allPermissions;

	public function allPermissions() {
		if (!$this->allPermissions) {
			$options = array();
			$definers = ClassInfo::implementorsOf('PermissionDefiner');
			$perms = array();
			foreach ($definers as $definer) {
				$cls = new $definer();
				$perms = array_merge($perms, $cls->definePermissions());
			}

			$this->allPermissions = array_combine($perms, $perms);
		}

		return $this->allPermissions;
	}

	/**
	 * Grants a specific permission to a given user or group
	 *
	 * @param string $perm
	 * @param Member|Group $to
	 */
	public function grant(DataObject $node, $perm, DataObject $to, $grant = 'GRANT') {
		// make sure we can !!
		if (!$this->checkPerm($node, 'ChangePermissions')) {
			throw new PermissionDeniedException("You do not have permission to do that");
		}

		$role = DataObject::get_one('AccessRole', '"Title" = \'' . Convert::raw2sql($perm) . '\'');

		$composedOf = array($perm);
		if ($role && $role->exists()) {
			$composedOf = $role->Composes->getValues();
		}

		$type = $to instanceof Member ? 'Member' : 'Group';
		$filter = array(
			'Type =' => $type,
			'AuthorityID =' => $to->ID,
			'ItemID =' => $node->ID,
			'ItemType =' => $node->class,
			'Grant =' => $grant,
		);

		$existing = DataObject::get_one('AccessAuthority', singleton('SiteUtils')->dbQuote($filter));
		if (!$existing || !$existing->exists()) {
			$existing = new AccessAuthority;
			$existing->Type = $type;
			$existing->AuthorityID = $to->ID;
			$existing->ItemID = $node->ID;
			$existing->ItemType = $node->class;
			$existing->Grant = $grant;
		}

		$currentRoles = $existing->Perms->getValues();
		if (!$currentRoles) {
			$new = $composedOf;
		} else {
			$new = array_merge($currentRoles, $composedOf);
		}

		$new = array_unique($new);
		$existing->Perms = $new;
		$existing->write();

		foreach ($new as $perm) {
			$key = $this->permCacheKey($node, $perm);
			$this->getCache()->remove($key);
		}
	}
	
	/**
	 * Return true or false as to whether a given user can access an object
	 * 
	 * @param type $perm
	 * @param type $member
	 * @param type $alsoFor
	 * @return type 
	 */
	public function checkPerm(DataObject $node, $perm, $member=null) {
		if (!$member) {
			$member = singleton('SecurityContext')->getMember();
		}
		if (is_int($member)) {
			$member = DataObject::get_by_id('Member', $member);
		}

		if (Permission::check('ADMIN')) {
			return true;
		}

		// if no member, just check public view
		$public = $this->checkPublicPerms($node, $perm);
		if ($public) {
			return true;
		}
		if (!$member) {
			return false;
		}

		// see whether we're the owner, and if the perm we're checking is in that list
		if ($this->checkOwnerPerms($node, $perm, $member)) {
			return true;
		}

		$permCache = $this->getCache();
		/* @var $permCache Zend_Cache_Core */

		$accessAuthority = '';

		$key = $this->permCacheKey($node, $perm);
		$userGrants = $permCache->load($key);

		$directGrant = null;

		if ($userGrants && isset($userGrants[$member->ID])) {
			$directGrant = $userGrants[$member->ID];
		} else {
			$userGrants = array();
		}

		$can = false;

		if (!$directGrant) {
			$filter = array(
				'ItemID =' => $node->ID,
				'ItemType =' => $node->class,
			);

			// get all access authorities for this object
			$existing = DataObject::get('AccessAuthority', singleton('SiteUtils')->dbQuote($filter));

			$groups = $member ? $member->Groups() : array();
			$gids = array();
			if ($groups && $groups->Count()) {
				$gids = $groups->map('ID', 'ID');
			}

			$can = false;
			$directGrant = 'NONE';
			if ($existing && $existing->count()) {
				foreach ($existing as $access) {
					// check if this mentions the perm in question
					$perms = $access->Perms->getValues();
					if (!in_array($perm, $perms)) {
						continue;
					}

					$grant = null;
					if ($access->Type == 'Group') {
						if (isset($gids[$access->AuthorityID])) {
							$grant = $access->Grant;
						}
					} else {
						if ($member->ID == $access->AuthorityID) {
							$grant = $access->Grant;
						}
					}

					if ($grant) {
						// if it's deny, we can just break away immediately, otherwise we need to evaluate all the 
						// others in case there's another DENY in there somewhere
						if ($grant == 'DENY') {
							$directGrant = 'DENY';
							// immediately break
							break;
						} else {
							// mark that it's been granted for now
							$directGrant = 'GRANT';
						}
					}
				}
			}

			$userGrants[$member->ID] = $directGrant;
			$permCache->save($userGrants, $key);
		}

		// return immediately if we have something
		if ($directGrant === 'GRANT') {
			return true;
		}
		if ($directGrant === 'DENY') {
			return false;
		}

		// otherwise query our parents
		if ($node->InheritPerms) {
			$permParent = $node->effectiveParent();
			return $permParent ? $this->checkPerm($permParent, $perm, $member) : false;
		}

		return false;
	}

	/**
	 * Checks the permissions for a public user
	 * 
	 * @param string $perm 
	 */
	public function checkPublicPerms(DataObject $node, $perm) {
		if ($perm == 'View') {
			if ($node->PublicAccess) {
				return true;
			}

			$parent = $node->effectiveParent();
			if ($parent) {
				return $this->checkPublicPerms($parent, $perm);
			}
		}
		return false;
	}

	/**
	 * Is the member the owner of this object, and is the permission being checked
	 * in the list of permissions that owners have?
	 *
	 * @param string $perm
	 * @param Member $member
	 * @return boolean
	 */
	protected function checkOwnerPerms(DataObject $node,$perm, $member) {
		$ownerId = $node->OwnerID;
		if ($node->isChanged('OwnerID')) {
			$changed = $node->getChangedFields();
			$ownerId = isset($changed['OwnerID']['before']) ? $changed['OwnerID']['before'] : $ownerId;
		}
		if (!$member || ($ownerId != $member->ID)) {
			return false;
		}

		$cache = $this->getCache();

		$perms = $cache->load('ownerperms');
		if (!$perms) {
			// find the owner role and take the permissions of it 
			$ownerRole = DataObject::get_one('AccessRole', '"Title" = \'Owner\'');
			if ($ownerRole && $ownerRole->exists()) {
				$perms = $ownerRole->Composes->getValues();
				if (is_array($perms)) {
					$cache->save($perms, 'ownerperms');
				}
			}
		}

		if (is_array($perms) && in_array($perm, $perms)) {
			return true;
		}
	}
	
	/**
	 *
	 * @param DataObject $node
	 * @param boolean $includeInherited 
	 *			Include inherited permissions in the list?
	 */
	public function getPermissionsFor(DataObject $node, $includeInherited = false) {
		if ($this->checkPerm($node, 'ViewPermissions')) {
			$authorities = $node->getAuthorities();
			if (!$authorities) {
				$authorities = new DataObjectSet();
			} else {
				foreach ($authorities as $authority) {
					$auth = $authority->getAuthority();
					$authority->DisplayName = $auth->getTitle();
					$authority->PermList = implode(',', $authority->Perms->getValues());
				}
			}
			return $authorities;
		}
	}

	/**
	 * Clear any cached permissions for this object
	 *
	 * @param DataObject $item
	 * @param type $perm 
	 */
	public function clearPermCacheFor(DataObject $item, $perm) {
		$key = $this->permCacheKey($item, $perm);
		// clear caching
		$this->getCache()->remove($key);
	}
	
	/**
	 * Get the key for this item in the cache
	 * 
	 * @param type $perm
	 * @return string
	 */
	public function permCacheKey(DataObject $node, $perm) {
		return md5($perm . '-' . $node->ID . '-' . $node->class);
	}
}

class PermissionDeniedException extends Exception {
	
}