#!/usr/bin/env php
<?php
// SPDX-License-Identifier: GPL-2.0-only

const MAX_BODYLEN = 2048;

// GNU/Weeb
const TARGET_CHAT_ID = -1001483770714;

// // Private Cloud
// const TARGET_CHAT_ID = -1001226735471;

const DATA_DIR = __DIR__."/../data";
const LOCK_FILE = DATA_DIR."/inbox.lock";

require __DIR__."/../lib.php";

function listTelegramLookup(array $list): array
{
	$ret = [];

	if (!is_dir(DATA_DIR))
		mkdir(DATA_DIR);

	if (!is_dir(DATA_DIR."/tg"))
		mkdir(DATA_DIR."/tg");

	foreach ($list as $c) {
		if (preg_match("/.+\<(.+?)\>/", $c, $m))
			$c = $m[1];

		$u = DATA_DIR."/tg/".$c;
		if (!file_exists($u))
			continue;

		$ret[] = trim(file_get_contents($u));
	}

	return $ret;
}

function tgMsgIdInsert(string $msgId, int $tgMsgId): bool
{
	if (!is_dir(DATA_DIR))
		mkdir(DATA_DIR);

	if (!is_dir(DATA_DIR."/tg_msg_id"))
		mkdir(DATA_DIR."/tg_msg_id");

	$file = DATA_DIR."/tg_msg_id/".sha1($msgId);
	return (bool) file_put_contents($file, (string) $tgMsgId);
}

function tgMsgIdLookup(string $msgId): int
{
	$file = DATA_DIR."/tg_msg_id/".sha1($msgId);
	if (!file_exists($file))
		return 0;

	return (int) file_get_contents($file);
}

function extractList(string $str): array
{
	$sz = strlen($str);
	if (!$sz)
		return [];

	$tmp = "";
	$container = [];
	$is_in_quotes = false;
	for ($i = 0; $i <= $sz; $i++) {
		if ($i >= $sz)
			goto g;
		$c = $str[$i];
		if ($c == '"')
			$is_in_quotes = !$is_in_quotes;

		if (($c == "," && !$is_in_quotes)) {
	g:
			$container[] = trim($tmp);
			$tmp = "";
			continue;
		}
		$tmp .= $c;
	}
	return $container;
}

function buildList(array $list, string $name): string
{
	$ret = "";
	foreach ($list as $c)
		$ret .= "{$name}: ".trim($c)."\n";

	return trim($ret);
}

// Thanks to https://stackoverflow.com/a/2955878/7275114
function slugify($text, string $divider = '-'): string
{
	// replace non letter or digits by divider
	$text = preg_replace('~[^\pL\d]+~u', $divider, $text);

	// transliterate
	$text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

	// remove unwanted characters
	$text = preg_replace('~[^-\w]+~', '', $text);

	// trim
	$text = trim($text, $divider);

	// remove duplicate divider
	$text = preg_replace('~-+~', $divider, $text);

	// lowercase
	$text = strtolower($text);

	if (empty($text))
		return 'n-a';

	return $text;
}

function clean_header_val(string $str): string
{
	$ex = explode("\n", $str);
	foreach ($ex as &$v)
		$v = trim($v);
	return implode(" ", $ex);
}

function fx(string $input): int
{
	$tmpName = "/tmp/".date("Y_m_d_H_i_s_").rand().microtime(true).".txt";
	file_put_contents($tmpName, $input);
	shell_exec("/usr/local/bin/public-inbox-mda < {$tmpName}");
	unlink($tmpName);

	$hdr = explode("\n\n", $input, 2);
	if (count($hdr) < 2) {
		$err = "Cannot split header and body";
		goto out;
	}

	$body = trim($hdr[1]);
	$hdr = $hdr[0];

	if (!preg_match("/(?:^|\\n)subject:\s+?(.+?)(?:\\n\S+\:|\\n\\n)/si", $hdr, $m)) {
		$err = "Cannot get the \"subject\" line";
		goto out;
	}
	$subject = clean_header_val($m[1]);

	if (!preg_match("/(?:^|\\n)from:\s+?(.+?)(?:\\n\S+\:|\\n\\n)/si", $hdr, $m)) {
		$err = "Cannot get the \"from\" line";
		goto out;
	}
	$from = clean_header_val($m[1]);

	if (!preg_match("/(?:^|\\n)date:\s+?(.+?)(?:\\n\S+\:|\\n\\n)/si", $hdr, $m)) {
		$err = "Cannot get the \"date\" line";
		goto out;
	}
	$date = clean_header_val($m[1]);

	if (!preg_match("/(?:^|\\n)message-id:\s+?(.+?)(?:\\n\S+\:|\\n\\n)/si", $hdr, $m)) {
		$err = "Cannot get the \"message-id\" line";
		goto out;
	}
	$msgId = $m[1];
	if ($msgId[0] === '<' && $msgId[strlen($msgId) - 1] === '>')
		$msgId = substr(clean_header_val($msgId), 1, -1);

	if (preg_match("/(?:^|\\n)in-reply-to:\s+?(.+?)(?:\\n\S+\:|\\n\\n)/si", $hdr, $m)) {
		$inReplyTo = $m[1];
		if ($inReplyTo[0] === '<' && $inReplyTo[strlen($inReplyTo) - 1] === '>')
			$inReplyTo = substr(clean_header_val($inReplyTo), 1, -1);
	} else {
		$inReplyTo = NULL;
	}

	$err = "";
	if (preg_match("/(?:^|\\n)to:\s+?(.+?)(?:\\n\S+\:|\\n\\n)/si", $hdr, $m)) {
		$toList = extractList(clean_header_val($m[1]));
		$toListStr = buildList($toList, "To");
	} else {
		$toListStr = "";
	}

	if (preg_match("/(?:^|\\n)cc:\s+?(.+?)(\\n\S+\:|\\n\\n)/si", $hdr, $m)) {
		$ccList = extractList(clean_header_val($m[1]));
		$ccListStr = buildList($ccList, "Cc");
	} else {
		$ccListStr = "";
	}

	$content = str_replace("\t", "        ", $body);
	$content = trim(substr($content, 0, MAX_BODYLEN));
	$msg = "#ml\nFrom: {$from}\n";

	if ($toListStr)
		$msg .= "{$toListStr}\n";

	if ($ccListStr)
		$msg .= "{$ccListStr}\n";

	$tgCCs = array_merge(...[
		listTelegramLookup($toList ?? []),
		listTelegramLookup($ccList ?? [])
	]);
	$tgCCs = array_unique($tgCCs);

	if (count($tgCCs) > 0)
		$msg .= "Telegram-Cc: ".implode(" ", $tgCCs)."\n";

	$msg .= "Date: {$date}\n";
	$msg .= "Subject: {$subject}";

	$replyMarkup = [
		"inline_keyboard" => [
			[
				[
					"text" => "See the full message",
					"url" => "https://lore.gnuweeb.org/gwml/".urlencode($msgId),
				]
			]
		]
	];

	$replyToTgMsgId = $inReplyTo ? tgMsgIdLookup($inReplyTo) : 0;

	if (strtolower(substr($subject, 0, 4)) !== "re: " &&
	    preg_match("/\[.*(?:patch|rfc).*?(?:(\d+)\/(\d+))?\](.+)/i", $subject, $m) &&
	    preg_match("/diff --git/", $body)) {

		$tmpDir = "/tmp/".date("Y_m_d_H_i_s_").rand();
		mkdir($tmpDir);

		$n = (int)$m[1];
		if (!$n)
			$n = 1;

		$file = sprintf("%s/%04d-%s.patch", $tmpDir, $n, substr(slugify(trim($m[3])), 0, 40));
		file_put_contents($file, $input);

		$o = sendFile([
			"chat_id" => TARGET_CHAT_ID,
			"document" => new \CurlFile($file),
			"caption" => "#patch ".$msg,
			"reply_markup" => json_encode($replyMarkup),
			"reply_to_message_id" => $replyToTgMsgId
		]);
		unlink($file);
		rmdir($tmpDir);
	} else {
		if (preg_match("/kernel test robot/", $from)) {
			// Skip the android tree.
			if (preg_match("/android/", $subject))
				return 0;
		} else {
			$msg .= "\n\n{$content}";
			if (strlen($body) > MAX_BODYLEN)
				$msg .= "...";
		}

		$msg = htmlspecialchars($msg, ENT_QUOTES, "UTF-8");
		$msg .= "\n<code>------------------------------------------------------------------------</code>";

		$o = sendMessage($msg, TARGET_CHAT_ID,
			[
				"parse_mode" => "HTML",
				"reply_markup" => $replyMarkup,
				"reply_to_message_id" => $replyToTgMsgId
			]
		);
	}

	if (isset($o["result"]["message_id"]))
		tgMsgIdInsert($msgId, $o["result"]["message_id"]);

out:
	if ($err)
		echo $err;
	return 0;
}


function main(): int
{
	if (!is_dir(DATA_DIR))
		mkdir(DATA_DIR);

	$handle = fopen(LOCK_FILE, "a");
	if (!$handle) {
		printf("Cannot open the lock file: %s\n", LOCK_FILE);
		return 1;
	}
	flock($handle, LOCK_EX);
	$ret = fx(file_get_contents("php://stdin"));
	flock($handle, LOCK_UN);
	fclose($handle);
	return $ret;
}

exit(main());
