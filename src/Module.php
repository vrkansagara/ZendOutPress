<?php

namespace ZendOutPress;

use Zend\EventManager\Event;
use Zend\ModuleManager\Feature\ConfigProviderInterface;

class Module implements ConfigProviderInterface
{
    const VERSION = '1.0.0';

    private $bufferOldSize;

    private $bufferNewSize;

    private $moduleConfig;

    public function onBootstrap(Event $event)
    {
        $app = $event->getTarget();
        $app->getEventManager()->attach('finish', array($this, 'compressOutPut'), 100);
    }


    public function getConfig()
    {
        return $this->moduleConfig = include __DIR__ . '/../config/module.config.php';
    }


    public function compressOutPut(Event $event)
    {
        $response = $event->getResponse();
        $content = $response->getBody();
        $config = $this->moduleConfig;

        $pcreRecursionLimit = ini_get('pcre.recursion_limit');
        $zlibOutputCompression = ini_get('zlib.output_compression');
        $zlibOutputCompressionLevel = ini_get('zlib.output_compression_level');

        ini_set('pcre.recursion_limit', '16777');
        ini_set('zlib.output_compression', 4096); // Some browser cant get content type.
        ini_set('zlib.output_compression_level', -1); // Let server decide.

        if ($config['is_debug']) {
            $this->bufferOldSize = strlen($content);
        }
        $whiteSpaceRules = array(
            '/(\s)+/s' => '\\1',// shorten multiple whitespace sequences
            "#>\s+<#" => ">\n<",  // Strip excess whitespace using new line
            "#\n\s+<#" => "\n<",// strip excess whitespace using new line
            '/\>[^\S ]+/s' => '>',
            // Strip all whitespaces after tags, except space
            '/[^\S ]+\</s' => '<',// strip whitespaces before tags, except space
            /**
             * '/\s+     # Match one or more whitespace characters
             * (?!       # but only if it is impossible to match...
             * [^<>]*   # any characters except angle brackets
             * >        # followed by a closing bracket.
             * )         # End of lookahead
             * /x',
             */
            //            '/\s+(?![^<>]*>)/x' => '', //Remove all whitespaces except content between html tags. //MOST DANGEROUS
        );
        $commentRules = array(
            "/<!--.*?-->/ms" => '',// Remove all html comment.,
        );
        $replaceWords = array(
            //OldWord will be replaced by the NewWord
            //              '/\bOldWord\b/i' =>'NewWord' // OldWord <-> NewWord DO NOT REMOVE THIS LINE. {REFERENCE LINE}
        );
        $allRules = array_merge(
            $replaceWords,
            $commentRules,
            $whiteSpaceRules
        );
        $buffer = $this->compressJscript($content);
        $buffer = preg_replace(array_keys($allRules), array_values($allRules), $buffer);
        $this->bufferNewSize = strlen($buffer);
        if ($config['is_debug']) {
            $old = $this->formatSizeUnits($this->bufferOldSize);
            $new = $this->formatSizeUnits($this->bufferNewSize);
            $percent = round(
                ($this->bufferNewSize / $this->bufferOldSize) * 100, 2
            );
            $buffer
                .= <<< EOF
<span>
Before : $old<br>
After  : $new <br>
Reduce : $percent%<br>
</span>
EOF;
        }

        ini_set('pcre.recursion_limit', $pcreRecursionLimit);
        ini_set('zlib.output_compression', $zlibOutputCompression); // Some browser cant get content type.
        ini_set('zlib.output_compression_level', $zlibOutputCompressionLevel); // Let server decide.
        // do stuff here
        return $response->setContent($buffer);
    }

    /**
     * This method will no longer support.
     *
     * @note Code will be healed even after marked as @deprecated for further reference.
     * @param $buffer
     *
     * @return null|string|string[] Compressed output
     * @deprecated
     *
     */
    public static function compress($buffer)
    {
        /**
         * To remove useless whitespace from generated HTML, except for Javascript.
         * [Regex Source]
         * https://github.com/bcit-ci/codeigniter/wiki/compress-html-output
         * http://stackoverflow.com/questions/5312349/minifying-final-html-output-using-regular-expressions-with-codeigniter
         * %# Collapse ws everywhere but in blacklisted elements.
         * (?>             # Match all whitespaces other than single space.
         * [^\S ]\s*     # Either one [\t\r\n\f\v] and zero or more ws,
         * | \s{2,}        # or two or more consecutive-any-whitespace.
         * ) # Note: The remaining regex consumes no text at all...
         * (?=             # Ensure we are not in a blacklist tag.
         * (?:           # Begin (unnecessary) group.
         * (?:         # Zero or more of...
         * [^<]++    # Either one or more non-"<"
         * | <         # or a < starting a non-blacklist tag.
         * (?!/?(?:textarea|pre)\b)
         * )*+         # (This could be "unroll-the-loop"ified.)
         * )             # End (unnecessary) group.
         * (?:           # Begin alternation group.
         * <           # Either a blacklist start tag.
         * (?>textarea|pre)\b
         * | \z          # or end of file.
         * )             # End alternation group.
         * )  # If we made it here, we are not in a blacklist tag.
         * %ix
         */
        $regexRemoveWhiteSpace
            = '%(?>[^\S ]\s*| \s{2,})(?=(?:(?:[^<]++| <(?!/?(?:textarea|pre)\b))*+)(?:<(?>textarea|pre)\b|\z))%ix';
        $new_buffer = preg_replace($regexRemoveWhiteSpace, '', $buffer);
        // We are going to check if processing has working
        if ($new_buffer === null) {
            $new_buffer = $buffer;
        }
        return $new_buffer;
    }

    public function formatSizeUnits($size)
    {
        $base = log($size) / log(1024);
        $suffix = array('', 'KB', 'MB', 'GB', 'TB');
        $f_base = floor($base);
        return round(pow(1024, $base - floor($base)), 2) . $suffix[$f_base];
    }

    public function compressJscript($buffer)
    {
        // JavaScript compressor by John Elliot <jj5@jj5.net>
        $replace = array(
            '#\'([^\n\']*?)/\*([^\n\']*)\'#' => "'\1/'+\'\'+'*\2'",
            // remove comments from ' strings
            '#\"([^\n\"]*?)/\*([^\n\"]*)\"#' => '"\1/"+\'\'+"*\2"',
            // remove comments from " strings
            '#/\*.*?\*/#s' => "",// strip C style comments
            '#[\r\n]+#' => "\n",
            // remove blank lines and \r's
            '#\n([ \t]*//.*?\n)*#s' => "\n",
            // strip line comments (whole line only)
            '#([^\\])//([^\'"\n]*)\n#s' => "\\1\n",
            // strip line comments
            // (that aren't possibly in strings or regex's)
            '#\n\s+#' => "\n",// strip excess whitespace
            '#\s+\n#' => "\n",// strip excess whitespace
            '#(//[^\n]*\n)#s' => "\\1\n",
            // extra line feed after any comments left
            // (important given later replacements)
            '#/([\'"])\+\'\'\+([\'"])\*#' => "/*"
            // restore comments in strings
        );
        $script = preg_replace(array_keys($replace), $replace, $buffer);
        $replace = array(
            "&&\n" => "&&",
            "||\n" => "||",
            "(\n" => "(",
            ")\n" => ")",
            "[\n" => "[",
            "]\n" => "]",
            "+\n" => "+",
            ",\n" => ",",
            "?\n" => "?",
            ":\n" => ":",
            ";\n" => ";",
            "{\n" => "{",
            //  "}\n"  => "}", (because I forget to put semicolons after function assignments)
            "\n]" => "]",
            "\n)" => ")",
            "\n}" => "}",
            "\n\n" => "\n",
        );
        $script = str_replace(array_keys($replace), $replace, $script);
        return trim($script);
    }
}