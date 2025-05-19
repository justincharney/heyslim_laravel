<?php

namespace App\Utils;

class StringUtils
{
    /**
     * Remove words ending with a dot (like titles, e.g., Dr., Mr.) from a string.
     *
     * @param string $text The input string.
     * @return string The cleaned string.
     */
    public static function removeTitles(string $text): string
    {
        // Remove any word that ends with a dot followed by optional whitespace
        $cleanedText = preg_replace("/\b\w+\.\s*/i", "", $text);
        return trim($cleanedText);
    }
}
