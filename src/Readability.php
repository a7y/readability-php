<?php

namespace alextyrey;

class Readability
{
    /**
     * What character set the site should be parsed as.
     */
    const CHARSET = 'utf-8';

    /**
     * What attribute should we give each element
     */
    const SCORE_ATTR = 'contentScore';

    /**
     * Keep the source of our HTML document in a variable
     * to modify later on.
     */
    protected $source = '';

    /**
     * Keep a DOM tree of our document to grab the content
     * from and parse.
     */
    protected $DOM = null;

    /**
     * Keep a log of all the nodes that matched our filter.
     */
    private $parentNodes = [];

    /**
     * See if we can find and store a lead image.
     */
    private $image = null;

    /**
     * Tags to strip from our document
     */
    private $junkTags = [
        //  External scripts
        'style', 'iframe', 'script', 'noscript', 'object',
        'applet', 'frame', 'embed', 'frameset', 'link',

        //  Ridiculously out-of-date tags
        'basefont', 'bgsound', 'blink', 'keygen', 'command',
        'menu', 'marquee',

        //  Form objects
        'form', 'button', 'input', 'textarea', 'select',
        'label', 'option',

        //  New HTML5 tags
        //  via ridcully/php-readability
        'canvas', 'datalist', 'nav', 'command',

        //  Other injected scripts
        'id="disqus_thread"', 'href="http://disqus.com"'
    ];

    /**
     * Attributes to remove from any tags we have, as they could
     * pose a security risk/look shonky.
     */
    private $junkAttrs = [
        'style', 'class', 'onclick', 'onmouseover',
        'align', 'border', 'margin'
    ];

    /**
     * Set up the source, load a DOM document to parse.
     */
    public function __construct($source, $charset = 'utf-8')
    {
        if (!is_string($charset)) {
            $charset = self::CHARSET;
        }

        //  Store our source for later on
        $this->source = $source;

        //  Decode to UTF-8 (or whatever encoding you pick)
        $source = mb_convert_encoding($source, 'HTML-ENTITIES', $charset);

        //  Remove some of the weird HTML before parsing as DOM
        $source = $this->prepare($source);

        //  Create our DOM
        $this->DOM = new \DOMDocument('1.0', $charset);

        try {
            //  If it doesn't parse as valid XML, it's not valid HTML.
            if (!@$this->DOM->loadHTML('<?xml encoding="' . self::CHARSET . '">' . $source)) {
                throw new Exception('Content is not valid HTML!');
            }

            foreach ($this->DOM->childNodes as $item) {
                //  If it's a ProcessingInstruction node
                //  (i.e, an inline PHP/ASP script)
                //  remove it. We don't want no virus shit.
                if ($item->nodeType == XML_PI_NODE) {
                    $this->DOM->removeChild($item);
                }
            }

            //  Force UTF-8
            $this->DOM->encoding = self::CHARSET;
        } catch (Exception $e) {
        }
    }

    /**
     * Statically call our Readability class and parse
     *
     * @return string
     */
    public static function parse($url, $isContent = false)
    {
        if ($isContent === false) {
            $url = file_get_contents($url);
        }

        $class = new self($url, false);
        return $class->getContent();
    }

    /**
     * See if we can grab the title from the document
     *
     * @return String
     */
    public function getTitle($delimiter = ' - ')
    {
        $titleNode = $this->DOM->getElementsByTagName('title');

        if ($titleNode->length and $title = $titleNode->item(0)) {
            // stackoverflow.com/questions/717328/how-to-explode-string-right-to-left
            $title = trim($title->nodeValue);
            $result = array_map('strrev', explode($delimiter, strrev($title)));

            //  If there was a dash, return the bit before it
            //  If not, just return the whole thing
            $title = sizeof($result) > 1 ? array_pop($result) : $title;

            //  Split any other delimiters we might have missed
            $title = preg_replace('/[\-—–.•] */', '', $title);

            //  Strip out any dodgy characters
            return utf8_encode($title);
        }

        return null;
    }

    /**
     * Find lead image (if possible)
     *
     * @return string
     */
    public function getImage($node = false)
    {
        if ($node === false and $this->image) {
            return $this->image;
        }

        //  Grab all the images in our article
        $images = $node->getElementsByTagName('img');

        //  If we have some images
        //  and the first one is a valid element
        if ($images->length and $lead = $images->item(0)) {
            //  Return the image's URL
            return $lead->getAttribute('src');
        }

        return null;
    }

    /**
     * Grab and process our content, get the main image,
     * and return everything as an array for easy access.
     *
     * @return array
     */
    public function getContent()
    {
        if (!$this->DOM) {
            return false;
        }

        //  We need to grab our content beforehand,
        //  so let's do that here.
        $content = $this->processContent();

        //  Bring everything together
        return (object) [
            'lead_image' => $this->image,
            'word_count' => mb_strlen(strip_tags($content), self::CHARSET),
            'title' => $this->getTitle(),
            'content' => $content
        ];
    }

    /**
     * Strip unwanted tags from a DOM node.
     *
     * @return DOMDocument
     */
    private function removeJunkTag($node, $tag)
    {
        while ($item = $node->getElementsByTagName($tag)->item(0)) {
            $parent = $item->parentNode;
            $parent->removeChild($item);
        }

        return $node;
    }

    /**
     * Remove any unwanted attributes from our DOM nodes.
     *
     * @return DOMDocument
     */
    private function removeJunkAttr($node, $attr)
    {
        $tags = $node->getElementsByTagName('*');
        $i = 0;

        while ($tag = $tags->item($i++)) {
            $tag->removeAttribute($attr);
        }

        return $node;
    }

    /**
     * Assign a score to our attribute.
     *
     * @return int
     */
    private function score($attr)
    {
        $base = 25;
        $content = 'content|text|body|post';

        //  If we match anything that's definitely not content, kill the score
        if (preg_match("/(comment|meta|footer|footnote|sidebar|blogroll)/i", $attr)) {
            return -($base * 2);
        }

        $candidateRegex =
            "/((^|\\s)(post|hentry|entry[-]?(" . $content . ")?|article[-]?(" . $content . ")?)(\\s|$))/i";

        //  If we match anything that's likely to be an article or post, let's bump it up.
        if (preg_match($candidateRegex, $attr)) {
            return $base;
        }

        //  No matches, just leave the score as-is.
        return 1;
    }

    /**
     * Find the lead paragraph
     * Algorithm from: http://code.google.com/p/arc90labs-readability/
     *
     * @return DOMNode
     */
    private function getTopBox()
    {
        //  Get all paragraphs
        $paragraphs = $this->DOM->getElementsByTagName('p');

        //  Loop our paragraphs
        $i = 0;
        while ($paragraph = $paragraphs->item($i++)) {
            $parent = $paragraph->parentNode;
            $score = intval($parent->getAttribute(self::SCORE_ATTR));

            //  Don't just check the text, we're going to examine the class and ID
            //  attributes as well
            $class = $parent->getAttribute('class');
            $id = $parent->getAttribute('id');

            //  Get scores for our attributes
            $score += $this->score($class);
            $score += $this->score($id);

            //  Add a point for every paragraph inside our element
            //  It's more likely that a big block of text is going to be the focal point.
            if (strlen($paragraph->nodeValue) > 10) {
                $score += strlen($paragraph->nodeValue);
            }

            //  Set a content score to each node
            $parent->setAttribute(self::SCORE_ATTR, $score);

            //  Add our element back to its parent
            array_push($this->parentNodes, $parent);
        }

        //  Assume we won't find a match for now
        $match = null;

        //  Find the highest-scoring element and return that
        for ($i = 0, $len = count($this->parentNodes); $i < $len; $i++) {
            $parent = $this->parentNodes[$i];
            $score = intval($parent->getAttribute(self::SCORE_ATTR));
            $orgScore = intval($match ? $match->getAttribute(self::SCORE_ATTR) : 0);

            if ($score and $score > $orgScore) {
                $match = $parent;
            }
        }

        return $match;
    }

    /**
     * Process our page's content
     *
     * @return string
     */
    private function processContent()
    {
        //  Get our page's main content
        $content = $this->getTopBox();

        //  If there's no decent match, we can't process
        //  the page. So just quit while we're ahead.
        if ($content === null) {
            return false;
        }

        //  Create another DOM to process everything in,
        //  one last time.
        $target = new \DOMDocument;
        $target->appendChild($target->importNode($content, true));

        //  Find an image if possible
        $this->image = $this->getImage($target);

        //  Strip the tags we don't want any more
        foreach ($this->junkTags as $tag) {
            $target = $this->removeJunkTag($target, $tag);
        }

        //  Strip any unwanted attributes as well
        foreach ($this->junkAttrs as $attr) {
            $target = $this->removeJunkAttr($target, $attr);
        }

        //  Hopefully we've got a lovely parsed document
        //  ready to give back to the user now.
        return mb_convert_encoding($target->saveHTML(), self::CHARSET, 'HTML-ENTITIES');
    }

    /**
     * Get our text ready for converting to a DOM document
     *
     * @return string
     */
    private function prepare($src)
    {
        //  Strip any character sets that don't match our ones
        preg_match('/charset=([\w|\-]+);?/', $src, $match);

        if (isset($match[1])) {
            $src = preg_replace('/charset=([\w|\-]+);?/', '', $src, 1);
        }

        //  Convert any double-line breaks to paragraphs
        $src = preg_replace('/<br\/?>[ \r\n\s]*<br\/?>/i', '</p><p>', $src);

        //  Remove any <font> tags
        $src = preg_replace("/<\/?font[^>]*>/i", '', $src);

        //  Strip any <script> tags
        $src = preg_replace("#<script(.*?)>(.*?)</script>#is", '', $src);

        //  Remove any extra whitespace
        return trim($src);
    }
}
