<?php
/** Western Punjabi (پنجابی)
 *
 * @file
 * @ingroup Languages
 *
 * @author Arslan
 * @author Kaganer
 * @author Khalid Mahmood
 * @author Rachitrali
 * @author Reedy
 * @author ZaDiak
 */

$linkPrefixExtension = true;
$fallback8bitEncoding = 'windows-1256';

$rtl = true;

$namespaceNames = [
	NS_MEDIA            => 'میڈیا',
	NS_SPECIAL          => 'خاص',
	NS_TALK             => 'گل_بات',
	NS_USER             => 'ورتنوالا',
	NS_USER_TALK        => 'ورتن_گل_بات',
	NS_PROJECT_TALK     => 'ویونت_گل_بات',
	NS_FILE             => 'فائل',
	NS_FILE_TALK        => 'فائل_گل_بات',
	NS_MEDIAWIKI        => 'میڈیا_وکی',
	NS_MEDIAWIKI_TALK   => 'میڈیاوکی_گل_بات',
	NS_TEMPLATE         => 'سانچہ',
	NS_TEMPLATE_TALK    => 'سانچہ_گل_بات',
	NS_HELP             => 'ہتھونڈائی',
	NS_HELP_TALK        => 'ہتھونڈائی_گل_بات',
	NS_CATEGORY         => 'گٹھ',
	NS_CATEGORY_TALK    => 'گٹھ_گل_بات',
];

$digitTransformTable = [
	'0' => '۰',
	'1' => '۱',
	'2' => '۲',
	'3' => '۳',
	'4' => '۴',
	'5' => '۵',
	'6' => '۶',
	'7' => '۷',
	'8' => '۸',
	'9' => '۹',
];

$numberingSystem = 'arabext';

$namespaceAliases = [
	'تصویر' => NS_FILE,
];

/** @phpcs-require-sorted-array */
$magicWords = [
	'redirect' => [ '0', '#مڑجوڑ', '#REDIRECT' ],
];
