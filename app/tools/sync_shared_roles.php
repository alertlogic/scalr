<?php
	require_once(dirname(__FILE__).'/../src/prepend.inc.php');

	set_time_limit(0);

	$location = "https://my.scalr.net/storage/shared-roles.php?scalr_id=".SCALR_ID;
	if (isset($argv[1])) {
		$location = $argv[1];
	} 
	$dump = @file_get_contents($location);	
	
	$roles = @json_decode($dump, true);	
	if (count($roles) < 1)
	    die("Unable to import shared roles: {$dump}");

    try {
		foreach ($roles as $role) {
            $chk2 = $db->GetRow("SELECT name, origin FROM roles WHERE id=? LIMIT 1", array($role['id']));
            if ($chk2['name'] && ($chk2['name'] != $role['name'] || $chk2['origin'] != $role['origin'])) {
                print "Role ID #{$role['id']} for role '{$role['name']}' taken by role '{$chk2['name']}'\n";
                continue;
            }

			$chk = $db->GetOne("SELECT id FROM roles WHERE origin=? AND id=? LIMIT 1", array($role['origin'], $role['id']));
			if (!$chk) {
				$db->Execute("INSERT INTO roles SET
					`id` = ?,
					`name` = ?,
					`origin` = ?,
					`client_id` = ?,
				    `cat_id` = ?,
					`env_id` = ?,
					`description` = ?,
					`behaviors` = ?,
					`history` = ?,
					`generation` = ?,
					`os` = ?,
				    `os_family` = ?,
				    `os_generation` = ?,
				    `os_version` = ?
				", array(
					$role['id'], $role['name'], $role['origin'], $role['client_id'], $role['cat_id'], $role['env_id'], $role['description'],
					$role['behaviors'], $role['history'],
					$role['generation'], $role['os'], $role['os_family'], $role['os_generation'], $role['os_version']
				));
			} else {
				$role['id'] = $chk;
				$db->Execute("DELETE FROM role_tags WHERE role_id = ?", array($role['id']));
				$db->Execute("DELETE FROM role_images WHERE role_id = ?", array($role['id']));
				$db->Execute("DELETE FROM role_software WHERE role_id =?", array($role['id']));
				$db->Execute("DELETE FROM role_security_rules WHERE role_id =?", array($role['id']));
				$db->Execute("DELETE FROM role_properties WHERE role_id =?", array($role['id']));
				$db->Execute("DELETE FROM role_parameters WHERE role_id = ?", array($role['id']));
				$db->Execute("DELETE FROM role_behaviors WHERE role_id =?", array($role['id']));
				$db->Execute("DELETE FROM role_behaviors WHERE role_id =?", array($role['id']));
				$db->Execute("DELETE FROM role_scripts WHERE role_id =?", array($role['id']));
			}

			foreach ($role['role_tags'] as $r1) {
			    try {
    				$db->Execute("INSERT INTO role_tags SET
    					`role_id` = ?,
    					`tag` = ?
    				", array($r1['role_id'], $r1['tag']));
			    } catch (Exception $e) {}
			}

			foreach ($role['role_software'] as $r2) {
				$db->Execute("INSERT INTO role_software SET
					`role_id` = ?,
					`software_name` = ?,
					`software_version` = ?,
					`software_key` = ?
				", array($r2['role_id'], $r2['software_name'], $r2['software_version'], $r2['software_key']));
			}

			foreach ($role['role_security_rules'] as $r3) {
				$db->Execute("INSERT INTO role_security_rules SET
					`role_id` = ?,
					`rule` = ?
				", array($r3['role_id'], $r3['rule']));
			}

			foreach ($role['role_properties'] as $r5) {
			    try {
    				$db->Execute("INSERT INTO role_properties SET
    					`role_id` = ?,
    					`name` = ?,
    					`value` = ?
    				", array($r5['role_id'], $r5['name'], $r5['value']));
                } catch (Exception $e) { throw $e; }
			}

			foreach ($role['role_parameters'] as $r6) {
				$db->Execute("INSERT INTO role_parameters SET
					`role_id` = ?,
					`name` = ?,
					`type` = ?,
					`isrequired` = ?,
					`defval` = ?,
					`allow_multiple_choice` = ?,
					`options` = ?,
					`hash` = ?,
					`issystem` = ?
				", array($r6['role_id'], $r6['name'], $r6['type'], $r6['isrequired'], $r6['defval'], $r6['allow_multiple_choice'], $r6['options'], $r6['hash'], $r6['issystem']));
			}

			foreach ($role['role_images'] as $r7) {
				try {
    				$db->Execute("INSERT INTO role_images SET
    					`role_id` = ?,
    					`cloud_location` = ?,
    					`image_id` = ?,
    					`platform` = ?,
    				    `architecture` = ?,
    				    `agent_version` =?
    				", array($r7['role_id'], $r7['cloud_location'], $r7['image_id'], $r7['platform'], $r7['architecture'], $r7['agent_version']));
				} catch (Exception $e) { throw $e; }
			}

			foreach ($role['role_behaviors'] as $r8) {
			    try {
    				$db->Execute("INSERT INTO role_behaviors SET
    					`role_id` = ?,
    					`behavior` = ?
    				", array($r8['role_id'], $r8['behavior']));
			    } catch (Exception $e) { throw $e; }
			}
			
			foreach ($role['role_scripts'] as $r9) {
			    try {
    				$db->Execute('INSERT INTO role_scripts SET
						`role_id` = ?,
						`event_name` = ?,
						`target` = ?,
						`script_id` = ?,
						`version` = ?,
						`timeout` = ?,
						`issync` = ?,
						`params` = ?,
						`order_index` = ?,
						`hash` = ?
					', array(
						$r9['role_id'],
						$r9['event_name'],
						$r9['target'],
						$r9['script_id'],
						$r9['version'],
						$r9['timeout'],
						$r9['issync'],
						serialize($r9['params']),
						$r9['order_index'],
						Scalr_Util_CryptoTool::sault(12)
					));
			    } catch (Exception $e) { throw $e; }
			}
	      }
	  } catch (Exception $e) {
	      $db->RollbackTrans();
          var_dump($e->getMessage());
          exit();
	  }

      $db->CommitTrans();
?>