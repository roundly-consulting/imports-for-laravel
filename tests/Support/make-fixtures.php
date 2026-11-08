<?php

declare(strict_types=1);

/*
 * Writes the committed binary fixtures in tests/Fixtures (run once: php tests/Support/make-fixtures.php).
 * Everything else is generated per test by SpreadsheetFactory, XlsxBuilder or ZipCrafter.
 */

$dir = dirname(__DIR__).'/Fixtures';

// Windows-1250 bytes for Slovak diacritics (Š ž ľ č …): an ASCII-only 1250 file would be valid UTF-8.
$cp1250 = ['Š' => "\x8A", 'ž' => "\x9E", 'ľ' => "\xBE", 'č' => "\xE8", 'á' => "\xE1", 'é' => "\xE9", 'í' => "\xED", 'ô' => "\xF4", 'ť' => "\x9D", 'Ž' => "\x8E"];
file_put_contents($dir.'/windows-1250.csv', strtr("Inventárne číslo;Názov;Stav\nAST-1;Šťastný počítač;active\nAST-2;Žltá stolička;active\n", $cp1250));

// UTF-16 LE with its BOM — what Excel's "Unicode text" writes.
file_put_contents($dir.'/utf16le.csv', "\xFF\xFE".mb_convert_encoding("Asset tag,Name\nAST-1,Laptop\n", 'UTF-16LE', 'UTF-8'));

// Excel 97–2003 / password-protected workbooks start with the OLE compound-file signature.
file_put_contents($dir.'/legacy.xls', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 504));
file_put_contents($dir.'/encrypted.xlsx', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x01", 504));

// An OpenDocument spreadsheet: a ZIP whose first entry is its mimetype.
$zip = new ZipArchive;
$zip->open($dir.'/sheet.ods', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
$zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
$zip->addFromString('content.xml', '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"/>');
$zip->close();

echo "fixtures written\n";
