<?php

/*
 * The MIT License
 *
 * Copyright 2022 zozlak.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace quickRdfIo;

use XmlParser;
use rdfInterface\QuadIterator as iQuadIterator;
use rdfInterface\Parser as iParser;
use rdfInterface\Quad as iQuad;
use rdfInterface\DataFactory as iDataFactory;
use rdfInterface\BlankNode as iBlankNode;
use rdfInterface\NamedNode as iNamedNode;
use zozlak\RdfConstants as RDF;

/**
 * Description of RdfXmlParser
 *
 * @author zozlak
 */
class RdfXmlParser implements iParser, iQuadIterator {

    const RDF_ROOT            = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#RDF';
    const RDF_ABOUT           = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#about';
    const RDF_DATATYPE        = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#datatype';
    const RDF_RESOURCE        = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#resource';
    const RDF_NODEID          = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#nodeID';
    const RDF_ID              = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#ID';
    const RDF_DESCRIPTION     = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#Description';
    const RDF_PARSETYPE       = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#parseType';
    const RDF_ABOUTEACHPREFIX = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#aboutEachPrefix';
    const RDF_ABOUTEACH       = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#aboutEach';
    const PARSETYPE_RESOURCE  = 'Resource';
    const PARSETYPE_LITERAL   = 'Literal';
    const XML_BASE            = 'http://www.w3.org/XML/1998/namespacebase';
    const XML_LANG            = 'http://www.w3.org/XML/1998/namespacelang';
    const STATE_ROOT          = 'root';
    const STATE_NODE          = 'node';
    const STATE_PREDICATE     = 'predicate';
    const STATE_VALUE         = 'value';
    const STATE_XMLLITERAL    = 'xmlliteral'; //https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-XML-literals
    const CHUNK_SIZE          = 1000000;

    /**
     * 
     * @var array<string>
     */
    private static array $skipAttributes    = [
        self::RDF_ABOUT,
        self::RDF_ID,
        self::RDF_NODEID,
        self::RDF_RESOURCE,
        self::RDF_DATATYPE,
        self::RDF_PARSETYPE,
        self::XML_LANG,
    ];
    private static array $literalAttributes = [
        self::RDF_ID,
        self::RDF_DATATYPE,
        self::XML_LANG,
    ];
    use TmpStreamParserTrait;

    private iDataFactory $dataFactory;

    /**
     *
     * @var resource
     */
    private $input;
    private XmlParser $parser;
    private string $baseUri;
    private string $baseUriDefault;
    private string $baseUriEmpty;
    private ?string $datatype;

    /**
     * 
     * @var array<int, array<string, string>>
     */
    private array $langStack;

    /**
     * 
     * @var array<string, iNamedNode>
     */
    private array $elementIds;

    /**
     * 
     * @var array<iNamedNode | iBlankNode>
     */
    private array $subjectStack;

    /**
     * 
     * @var array<string>
     */
    private array $subjectChangeTagsStack;
    private iNamedNode $curPredicate;
    private string $curLang;
    private bool $cdataPredicate;
    private ?string $literalValue;
    private int $literalValueDepth;
    private iBlankNode | iNamedNode | null $reifyAs;
    private string $state;

    /**
     * 
     * @var array<string, array<string>>
     */
    private array $nmsp;

    /**
     * 
     * @var array<iQuad>
     */
    private array $triples;

    public function __construct(iDataFactory $dataFactory, string $baseUri = '') {
        $this->dataFactory    = $dataFactory;
        $this->baseUriDefault = $baseUri;
    }

    public function __destruct() {
        if (isset($this->parser)) {
            xml_parser_free($this->parser);
        }
    }

    public function setBaseUri(string $baseUri): void {
        $this->baseUriDefault = $baseUri;
    }

    public function current(): iQuad {
        return current($this->triples);
    }

    public function key() {
        return key($this->triples);
    }

    public function next(): void {
        next($this->triples);
        while (key($this->triples) === null && !feof($this->input)) {
            $this->triples = [];
            $ret           = xml_parse($this->parser, fread($this->input, self::CHUNK_SIZE) ?: '', false);
            if ($ret !== 1) {
                $this->reportError();
            }
        }
        if (feof($this->input)) {
            $ret = xml_parse($this->parser, '', true);
        }
    }

    public function parseStream($input): iQuadIterator {
        if (!is_resource($input)) {
            throw new RdfIoException("Input has to be a resource");
        }

        $this->input = $input;
        return $this;
    }

    public function rewind(): void {
        if (ftell($this->input) !== 0) {
            $ret = rewind($this->input);
            if ($ret !== true) {
                throw new RdfIoException("Can't seek in the input stream");
            }
        }
        if (isset($this->parser)) {
            xml_parser_free($this->parser);
        }
        $this->state                  = self::STATE_ROOT;
        $this->nmsp                   = [];
        $this->subjectStack           = [];
        $this->subjectChangeTagsStack = [];
        $this->elementIds             = [];
        $this->langStack              = [];
        $this->literalValueDepth      = 0;
        $this->curLang                = '';
        $this->reifyAs                = null;
        $this->parseBaseUri($this->baseUriDefault);
        $this->parser                 = xml_parser_create_ns('UTF-8', '');
        xml_parser_set_option($this->parser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($this->parser, XML_OPTION_SKIP_TAGSTART, 0);
        xml_parser_set_option($this->parser, XML_OPTION_SKIP_WHITE, 0);
        xml_set_element_handler($this->parser, fn($x, $y, $z) => $this->onElementStart($y, $z), fn($x, $y) => $this->onElementEnd($y));
        xml_set_character_data_handler($this->parser, fn($x, $y) => $this->onCData($y));
        xml_set_start_namespace_decl_handler($this->parser, fn($x, $y, $z) => $this->onNamespaceStart($x, $y, $z));
        xml_set_end_namespace_decl_handler($this->parser, fn($x, $y) => $this->onNamespaceEnd($x, $y));
        $this->triples                = [];
        $this->next();
    }

    public function valid(): bool {
        return key($this->triples) !== null;
    }

    /**
     * 
     * @param string $name
     * @param array<string, string> $attribs
     * @return void
     * @throws RdfIoException
     */
    private function onElementStart(string $name, array &$attribs): void {
        $prevState = $this->state;

        $this->setLangDatatype($name, $attribs);
        switch ($this->state) {
            case self::STATE_ROOT:
                $name === self::RDF_ROOT ? $this->onRoot($attribs) : $this->onNode($name, $attribs);
                break;
            case self::STATE_NODE:
                $this->onNode($name, $attribs);
                break;
            case self::STATE_PREDICATE:
                $this->onPredicate($name, $attribs);
                break;
            case self::STATE_VALUE:
                $prevSbj              = $this->getCurrentSubject();
                $curProp              = $this->curPredicate;
                $newSbj               = $this->onNode($name, $attribs);
                $this->addTriple($prevSbj, $curProp, $newSbj);
                $this->cdataPredicate = false;
                break;
            case self::STATE_XMLLITERAL:
                $this->onXmlLiteralElement($name, $attribs);
                break;
            default:
                throw new RdfIoException("Unknown parser state $this->state");
        }

        //echo "START $prevState=>$this->state $name ($this->literalValueDepth)\n";
    }

    /**
     * 
     * @param array<string, string> $attributes
     * @return void
     */
    private function onRoot(array &$attributes): void {
        $this->state = self::STATE_NODE;
        if (isset($attributes[self::XML_BASE])) {
            $this->parseBaseUri($attributes[self::XML_BASE]);
        }
    }

    /**
     * 
     * @param string $tag
     * @param array<string, string> $attributes
     * @return iNamedNode|iBlankNode
     */
    private function onNode(string $tag, array &$attributes): iNamedNode | iBlankNode {
        // standard conformance
        if (isset($attributes[self::RDF_ABOUTEACH]) || isset($attributes[self::RDF_ABOUTEACHPREFIX])) {
            throw new RdfIoException("Obsolete attribute '" . (isset($attributes[self::RDF_ABOUTEACH]) ? self::RDF_ABOUTEACH : self::RDF_ABOUTEACHPREFIX) . "' used");
        }

        // create subject
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-blank-nodes
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-ID-xml-base
        if (isset($attributes[self::RDF_ABOUT])) {
            $subject = $this->dataFactory->namedNode($this->resolveIri($attributes[self::RDF_ABOUT]) ?? '');
        } elseif (isset($attributes[self::RDF_ID])) {
            $subject = $this->handleElementId($attributes[self::RDF_ID]);
        } elseif (isset($attributes[self::RDF_NODEID])) {
            $subject = $this->dataFactory->blankNode('_:' . $attributes[self::RDF_NODEID]);
        } else {
            $subject = $this->dataFactory->blankNode();
        }
        $this->subjectStack[]           = $subject;
        $this->subjectChangeTagsStack[] = $tag;

        // type as tag
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-typed-nodes
        if ($tag !== self::RDF_DESCRIPTION) {
            $this->addTriple($subject, RDF::RDF_TYPE, $tag);
        }

        // predicates&values as attributes
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-property-attributes
        $attrToProcess = array_diff(array_keys($attributes), self::$skipAttributes);
        foreach ($attrToProcess as $attr) {
            $this->addTriple($subject, (string) $attr, $attributes[$attr], $this->curLang, $this->datatype ?? '');
        }

        // change the state
        $this->state = self::STATE_PREDICATE;
        return $subject;
    }

    /**
     * 
     * @param string $tag
     * @param array<string, string> $attributes
     * @return void
     */
    private function onPredicate(string $tag, array &$attributes): void {
        $this->state             = self::STATE_VALUE;
        // https://www.w3.org/TR/rdf-syntax-grammar/#emptyPropertyElt
        $this->cdataPredicate    = count(array_diff(array_keys($attributes), self::$literalAttributes)) === 0;
        $this->literalValue      = '';
        $this->literalValueDepth = 1;
        $this->curPredicate      = $this->dataFactory->namedNode($tag);
        $parseType               = $attributes[self::RDF_PARSETYPE] ?? '';
        $subjectTmp              = null;

        if (isset($attributes[self::RDF_RESOURCE])) {
            // rdf:resource attribute
            $subjectTmp = $this->dataFactory->namedNode($this->resolveIri($attributes[self::RDF_RESOURCE]) ?? '');
            $this->addTriple(null, $this->curPredicate, $subjectTmp);
        } elseif (isset($attributes[self::RDF_NODEID])) {
            // rdf:nodeID attribute
            $subjectTmp = $this->dataFactory->blankNode($attributes[self::RDF_NODEID]);
            $this->addTriple(null, $this->curPredicate, $subjectTmp);
        }

        // attributes as nested triples with implicit intermidiate node
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-property-attributes-on-property-element
        $attrToProcess = array_diff(array_keys($attributes), self::$skipAttributes);
        if (count($attrToProcess) > 0) {
            if ($subjectTmp === null) {
                $subjectTmp = $this->dataFactory->blankNode();
                $this->addTriple(null, $tag, $subjectTmp);
            }
            foreach ($attrToProcess as $attr) {
                $this->addTriple($subjectTmp, (string) $attr, $attributes[$attr], $this->curLang, $this->datatype ?? '');
            }
        }

        // implicit blank node due to parseType="Resource"
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-parsetype-resource
        if ($parseType === self::PARSETYPE_RESOURCE) {
            $blankNode                      = $this->dataFactory->blankNode();
            $this->addTriple(null, $tag, $blankNode);
            $this->subjectStack[]           = $blankNode;
            $this->subjectChangeTagsStack[] = $tag;
            $this->state                    = self::STATE_PREDICATE;
        }

        // XML literal value due to parseType="Literal"
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-XML-literals
        if ($parseType === self::PARSETYPE_LITERAL) {
            $this->state = self::STATE_XMLLITERAL;
        }

        // reification
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-reifying
        if (isset($attributes[self::RDF_ID])) {
            $this->reifyAs = $this->handleElementId($attributes[self::RDF_ID]);
            //echo "reifying as $this->reifyAs\n";
        }
    }

    private function onElementEnd(string $name): void {
        $prevState = $this->state;

        if ($this->state === self::STATE_VALUE && $this->cdataPredicate === true) {
            $this->addTriple(null, $this->curPredicate, $this->literalValue ?? '', $this->curLang, $this->datatype ?? '');
            $this->literalValue   = '';
            $this->cdataPredicate = false;
        } elseif ($this->state === self::STATE_XMLLITERAL) {
            // literal XML
            // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-XML-literals
            $this->literalValueDepth--;
            if ($this->literalValueDepth === 0) {
                $this->addTriple(null, $this->curPredicate, $this->literalValue ?? '', '', RDF::RDF_XML_LITERAL);
                $this->literalValue = '';
            } else {
                $this->literalValue .= "</" . $this->shorten($name) . ">";
            }
        } elseif ($this->state === self::STATE_PREDICATE && $name === end($this->subjectChangeTagsStack)) {
            //echo "removing from the subjects stack\n";
            array_pop($this->subjectStack);
            array_pop($this->subjectChangeTagsStack);
            $this->cdataPredicate = false;
        }

        $this->state = match ($this->state) {
            self::STATE_VALUE => self::STATE_PREDICATE,
            self::STATE_PREDICATE => count($this->subjectStack) > 0 ? self::STATE_VALUE : self::STATE_NODE,
            self::STATE_NODE => self::STATE_VALUE,
            self::STATE_XMLLITERAL => $this->literalValueDepth === 0 ? self::STATE_PREDICATE : self::STATE_XMLLITERAL,
            default => throw new RdfIoException("Wrong parser state $this->state"),
        };

        if ($name === (end($this->langStack) ?: [''])[0]) {
            $this->curLang = array_pop($this->langStack)[1];
        }

        //echo "END $prevState=>$this->state $name ($this->cdataPredicate,$this->literalValueDepth) (" . implode(', ', $this->subjectStack) . ")\n";
    }

    private function onNamespaceStart(XMLParser $parser, string $prefix,
                                      string $uri): void {
        //echo "NMSP SET $prefix $uri\n";
        if (!isset($this->nmsp[$prefix])) {
            $this->nmsp[$prefix] = [];
        }
        $this->nmsp[$prefix][] = $uri;
    }

    private function onNamespaceEnd(XMLParser $parser, string $prefix): void {
        //echo "NMSP UNSET $prefix\n";
        array_pop($this->nmsp[$prefix]);
    }

    private function onCData(string $data): void {
        if ($this->state === self::STATE_VALUE && $this->cdataPredicate !== false || $this->state === self::STATE_XMLLITERAL) {
            $this->cdataPredicate = true;
            $this->literalValue   .= $data;
            //echo "CDATA $this->state $this->literalValue\n";
        } else {
            //echo "CDATA $this->state (skip) $data\n";
        }
    }

    private function reportError(): void {
        $msg  = xml_error_string(xml_get_error_code($this->parser));
        $line = xml_get_current_line_number($this->parser);
        $col  = xml_get_current_column_number($this->parser);
        throw new RdfIoException("Error while parsing the file: $msg in line $line column $col");
    }

    private function addTriple(iBlankNode | iNamedNode | null $subject,
                               iNamedNode | string $predicate,
                               iNamedNode | iBlankNode | string $object,
                               string $lang = null, string $datatype = null): void {
        $df = $this->dataFactory;

        $subject = $subject ?? $this->getCurrentSubject();
        if (!($predicate instanceof iNamedNode)) {
            $predicate = $df->namedNode($predicate);
        }
        if (!empty($lang) || $datatype !== null) {
            $object = $df->literal($object, $lang, $datatype);
        } elseif (is_string($object)) {
            $object = $df->namedNode($object);
        }
        $triple          = $df->quad($subject, $predicate, $object);
        $this->triples[] = $triple;
        //echo "adding $triple\n";
        // reification
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-reifying
        if (!empty($this->reifyAs)) {
            $this->triples[] = $df->quad($this->reifyAs, $df->namedNode(RDF::RDF_SUBJECT), $subject);
            $this->triples[] = $df->quad($this->reifyAs, $df->namedNode(RDF::RDF_PREDICATE), $predicate);
            $this->triples[] = $df->quad($this->reifyAs, $df->namedNode(RDF::RDF_OBJECT), $object);
            $this->triples[] = $df->quad($this->reifyAs, $df->namedNode(RDF::RDF_TYPE), $df->namedNode(RDF::RDF_STATEMENT));
            $this->reifyAs   = null;
        }
    }

    /**
     * 
     * @param array<string, string> $attributes
     * @return void
     */
    private function setLangDatatype(string $elementName, array &$attributes): void {
        if (isset($attributes[self::XML_LANG])) {
            $this->langStack[] = [$elementName, $this->curLang];
            $this->curLang     = $attributes[self::XML_LANG];
        }
        $this->datatype = $this->resolveIri($attributes[self::RDF_DATATYPE] ?? null);
    }

    /**
     * 
     * @param string $name
     * @param array<string, string> $attributes
     * @return void
     */
    private function onXmlLiteralElement(string $name, array &$attributes): void {
        $name               = $this->shorten($name);
        $this->literalValue .= "<$name";
        if ($this->literalValueDepth === 1) {
            foreach ($this->nmsp as $alias => $prefix) {
                $this->literalValue .= ' xmlns:' . $alias . '="' . htmlspecialchars(end($prefix) ?: '', ENT_XML1, 'UTF-8') . '"';
            }
        }
        foreach ($attributes as $k => $v) {
            $this->literalValue .= ' ' . $this->shorten($k) . '="' . htmlspecialchars($v, ENT_XML1, 'UTF-8') . '"';
        }
        $this->literalValue .= ">";
        $this->literalValueDepth++;
    }

    private function shorten(string $uri): string {
        $longestPrefix       = '';
        $longestPrefixLength = 0;
        $bestAlias           = '';
        foreach ($this->nmsp as $alias => &$prefixes) {
            $prefix = end($prefixes) ?: '';
            $len    = strlen($prefix);
            if ($len > $longestPrefixLength && str_starts_with($uri, $prefix)) {
                $longestPrefix       = $prefix;
                $longestPrefixLength = $len;
                $bestAlias           = $alias;
            }
        }
        if (empty($bestAlias)) {
            return $uri;
        }
        return $bestAlias . ":" . substr($uri, $longestPrefixLength);
    }

    private function getCurrentSubject(): iNamedNode | iBlankNode {
        return end($this->subjectStack) ?: throw new RdfIoException("Subjects stack empty");
    }

    /**
     * https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-ID-xml-base
     * 
     * @param string $iri
     * @return string
     */
    private function resolveIri(?string $iri): ?string {
        if ($iri === null) {
            return null;
        }
        if (preg_match('`^[a-zA-Z][a-zA-Z0-9+-.]*://`', $iri)) {
            return $iri;
        } elseif (empty($iri)) {
            return $this->baseUriEmpty;
        } else {
            return $this->baseUri . $iri;
        }
    }

    private function handleElementId(string $id): iNamedNode {
        if (isset($this->elementIds[$id])) {
            throw new RdfIoException("Duplicated element id '$id'");
        }
        $this->elementIds[$id] = $this->dataFactory->namedNode($this->baseUriEmpty . '#' . $id);
        return $this->elementIds[$id];
    }

    private function parseBaseUri(string $baseUri): void {
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-baseURIs
        $bu                 = parse_url($baseUri);
        $path               = $bu['path'] ?? '/';
        $query              = isset($bu['query']) ? '?' . $bu['query'] : '';
        $baseUri            = (isset($bu['scheme']) ? $bu['scheme'] . '://' : '') .
            ($bu['host'] ?? '') .
            (isset($bu['port']) ? ':' . $bu['port'] : '');
        $this->baseUriEmpty = $baseUri . $path . $query;
        $path               = explode('/', $path);
        if (!empty(end($path))) {
            $path[count($path) - 1] = '';
        }
        $path          = implode('/', $path);
        $this->baseUri = $baseUri . $path;
    }
}
