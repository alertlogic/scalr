<?php
	require_once(dirname(__FILE__).'/../src/prepend.inc.php');

	set_time_limit(0);
	
    
	$location = $argv[1];
	$dump = @file_get_contents($location);	
	
	$scripts = @json_decode($dump, true);	
	if (count($scripts) < 1)
	    die("Unable to import shared scripts: {$dump}");

    try {
		foreach ($scripts as $script) {
            $chk2 = $db->GetRow("SELECT name, origin FROM scripts WHERE id=? LIMIT 1", array($script['id']));
            if ($chk2['name'] && ($chk2['name'] != $script['name'] || $chk2['origin'] != $script['origin'])) {
                print "Script ID #{$script['id']} for script '{$script['name']}' taken by script '{$chk2['name']}'\n";
                continue;
            }

			$chk = $db->GetOne("SELECT id FROM scripts WHERE origin=? AND id=? LIMIT 1", array($script['origin'], $script['id']));
			if (!$chk) {
				$db->Execute("INSERT INTO scripts SET
					`id` = ?,
					`name` = ?,
					`origin` = ?,
					`issync` = ?,
                    `clientid` = ?				    
				", array(
					$script['id'], $script['name'], $script['origin'], $script['issync'], $script['client_id']
				));
			} 

			foreach ($script['script_revisions'] as $r1) {
			    try {
    				$db->Execute("INSERT INTO script_revisions SET
    					`scriptid` = ?,
    					`revision` = ?,
                        `script` = ?,
                        `variables` = ?
    				", array($script['id'], $r1['revision'], $r1['content'], serialize($r1['variables'])));
			    } catch (Exception $e) {}
			}
        }   
	} catch (Exception $e) {
	    $db->RollbackTrans();
        var_dump($e->getMessage());
        exit();
	}

    $db->CommitTrans();
?>