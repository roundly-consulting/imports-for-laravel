<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

use XMLReader;

/**
 * The text of a `<si>` or `<is>` element: plain `<t>` or the `<t>` of every rich-text run,
 * concatenated, with phonetic `<rPh>` runs skipped and `_xHHHH_` escapes decoded.
 *
 * @internal
 */
final class RichText
{
    public static function read(Package $package, XMLReader $reader): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }

        $depth = $reader->depth;
        $text = '';
        $inText = false;
        $skipUntil = null;

        while ($package->read($reader)) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }

            if ($skipUntil !== null) {
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $skipUntil) {
                    $skipUntil = null;
                }

                continue;
            }

            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->localName === 'rPh' && ! self::isEmpty($reader)) {
                    $skipUntil = $reader->depth;
                } elseif ($reader->localName === 't') {
                    $inText = ! self::isEmpty($reader);
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 't') {
                $inText = false;
            } elseif ($inText && in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                $text .= $reader->value;
            }
        }

        return self::decode($text);
    }

    /**
     * Read through a method: the reader moves, so a narrowed property would lie.
     */
    private static function isEmpty(XMLReader $reader): bool
    {
        return $reader->isEmptyElement;
    }

    /**
     * `_x000D_` is a carriage return, `_x005F_` a literal underscore (so `_x005F_x000D_` reads as
     * the text `_x000D_`).
     */
    public static function decode(string $text): string
    {
        if (! str_contains($text, '_x')) {
            return $text;
        }

        return (string) preg_replace_callback('/_x([0-9A-Fa-f]{4})_/', static function (array $match): string {
            $code = (int) hexdec($match[1]);

            return mb_chr($code, 'UTF-8') ?: '';
        }, $text);
    }
}
