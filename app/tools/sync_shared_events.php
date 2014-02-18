<?php
	require_once(dirname(__FILE__).'/../src/prepend.inc.php');

	set_time_limit(0);
	    
	$location = $argv[1];
	$dump = @file_get_contents($location);	
	
	$events = @json_decode($dump, true);	
	if (count($events) < 1)
	    die("Unable to import shared events: {$dump}");

    try {
		foreach ($events as $event) {
            $chk2 = $db->GetRow("SELECT name FROM event_definitions WHERE id=? LIMIT 1", array($event['id']));
            if ($chk2['name'] && ($chk2['name'] != $event['name'])) {
                print "Event ID #{$event['id']} for event '{$event['name']}' taken by event '{$chk2['name']}'\n";
                continue;
            }						
			
			$db->Execute("REPLACE INTO event_definitions SET
				id = ?,
				name = ?,
				description = ?,
                env_id = ?
            ", array(
                $event['id'], $event['name'], $event['description'], 1
            ));
        }   
	} catch (Exception $e) {
	    $db->RollbackTrans();
        var_dump($e->getMessage());
        exit();
	}

    $db->CommitTrans();
?>