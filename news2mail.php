<?php
define("IN_MYBB", 1);

$basepath = dirname($_SERVER["SCRIPT_FILENAME"]);

require_once $basepath."/../global.php";
require MYBB_ROOT.'/syncom/config.php';
require_once "mybbapi.php";

function message2mail($fid, $tid, $message)
{
	global $db, $syncom, $mybb;

	$pos = strpos($message, "\r\n\r\n");
	if ($pos == 0)
		return("");

	$header = substr($message, 0, $pos);
	$body = substr($message, $pos+4);
	$subject = "";

	$lines = explode("\r\n", $header);

	$newheader = array();
	$inheader = false;
	foreach ($lines as $line) {
		$ignore = false;
		if (! (in_array(substr($line, 0, 1), array("\t", " ")))) $inheader = false;

		// Header entfernen
		if (strtoupper(substr($line, 0, 18)) == "NNTP-POSTING-DATE:") $ignore = true;
		if (strtoupper(substr($line, 0, 5)) == "XREF:") $ignore = true;
		if (strtoupper(substr($line, 0, 6)) == "LINES:") $ignore = true;
		if (strtoupper(substr($line, 0, 16)) == "X-COMPLAINTS-TO:") $ignore = true;

		if ($inheader)
			$subject .= $line;

		if (strtoupper(substr($line, 0, 8)) == "SUBJECT:") {
			$inheader = true;
			$subject = substr($line, 9);
			$ignore = true;
		}

		// Header abaendern
		if (strtoupper(substr($line, 0, 11)) == "NEWSGROUPS:") $line = "X-".$line;
		if (strtoupper(substr($line, 0, 7)) == "SENDER:") $line = "X-".$line;

		if (!$ignore)
			$newheader[] = $line;
	}

	// Header hinzufuegen
	$query = $db->simple_select("forums", "syncom_newsgroup", "fid=".$db->escape_string($fid), array('limit' => 1));
	if (!($forum = $db->fetch_array($query)))
		return("");

	$group = $forum["syncom_newsgroup"];
	$url = $mybb->settings["bburl"];

	$newheader[] = "Precedence: list";
	//$newheader[] = "To: <".$group."@".$syncom["mailhostname"].">";
	$newheader[] = "Reply-To: <".$group."@".$syncom["mailhostname"].">";
	$newheader[] = "List-Id: <".$group.">";
	$newheader[] = "List-Unsubscribe: <".$url."/forumdisplay.php?fid=61>";
	$newheader[] = "List-Archive: <".$url."/forumdisplay.php?fid=61>";
	$newheader[] = "List-Post: <mailto:".$group."@".$syncom["mailhostname"].">";
	//$newheader[] = "List-Help: <mailto:test-request@lists.piratenpartei.de?subject=help>";
	$newheader[] = "List-Subscribe: <".$url."/forumdisplay.php?fid=61>";
	$newheader[] = "Sender: ".$group."-bounces@".$syncom["mailhostname"];
	$newheader[] = "Errors-To: ".$group."-bounces@".$syncom["mailhostname"];

	return(array("list"=>$group."@".$syncom["mailhostname"], "subject"=>$subject, "header"=>implode("\r\n", $newheader), "body"=>$body));
}

function processmail($fid, $tid, $message) {
	global $db, $subuser;

	$mail = message2mail($fid, $tid, $message);

	if ($mail == "")
		return(false);

	//echo $mail."\n";
	//echo "----------------------------------------------------\n";

	$user = array();
	// Nach Forenabonnenten suchen
	$query = $db->simple_select("forumsubscriptions", "uid", "fid=".$db->escape_string($fid));
	while ($forensub = $db->fetch_array($query)) {
		$user[] = $forensub["uid"];
	}

	// Nach Threadabonnenten suchen
	$query = $db->simple_select("threadsubscriptions", "uid", "tid=".$db->escape_string($tid));
	while ($forensub = $db->fetch_array($query)) {
		$user[] = $forensub["uid"];
	}

	// Keine Abonnenten, also raus
	if (sizeof($user) == 0)
		return(true);

	foreach ($user as $target) {
		// Ist der Empfaenger in der Liste der Mailabonnenten
		if (array_key_exists($target, $subuser)) {
			// To-Do:
			// Nur ueber BCC versenden
			// Einmal absenden pro Nachricht und nicht pro Empfaenger
			if (!mail($mail['list'], $mail['subject'], $mail['body'], $mail['header']."\r\nBCC: ".$subuser[$target]))
				return(false);
			echo $target."-".$subuser[$target]."\n";
		}
	}
}

function processmails()
{
	global $syncom, $subuser;

	// Testweise
	// To-Do: Aus Config die UserID und Mailadresse holen
	$subuser = array("1"=>"ike@piratenpartei.de");

	$dir = scandir($syncom['mailout-spool'].'/');

	foreach ($dir as $spoolfile) {
		$file = $syncom['mailout-spool'].'/'.$spoolfile;
		if (!is_dir($file) and (file_exists($file))) {
			$message = unserialize(file_get_contents($file));
			processmail($message["info"]["fid"], $message["info"]["tid"], $message["message"]);
			/*if (($fid == 0) or processmail($api, $fid, $message['article'], $message['number']))
				@unlink($file);
			else
				rename($file, $syncom['mailout-spool'].'/error/'.$spoolfile);*/
		}
 	}
}

processmails();
?>