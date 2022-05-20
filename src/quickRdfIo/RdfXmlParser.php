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

class RdfXmlParserState {

    const STATE_ROOT       = 'root';
    const STATE_NODE       = 'node';
    const STATE_PREDICATE  = 'predicate';
    const STATE_VALUE      = 'value';
    const STATE_XMLLITERAL = 'xmlliteral'; //https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-XML-literals

    public string $state             = self::STATE_ROOT;
    public ?string $datatype          = null;
    public string $lang              = '';
    public iNamedNode | iBlankNode $subject;
    public iNamedNode $predicate;
    public ?string $literalValue      = null;
    public int $literalValueDepth = 0;
    public ?bool $isCDataPredicate  = null;
    public bool $isCollection      = false;
    public int $sequenceNo        = 1;
    public iBlankNode | iNamedNode | null $reifyAs           = null;

    public function withState(string $state): self {
        $copy        = clone($this);
        $copy->state = $state;
        return $copy;
    }

    public function withSubject(iNamedNode | iBlankNode $subject): self {
        $copy          = clone($this);
        $copy->subject = $subject;
        return $copy;
    }
}

/**
 * Streaming RDF-XML parser. Fast and with low memory footprint.
 * 
 * Known deviations from the RDF-XML specification:
 * 
 * - Doesn't resolve "/.." in relative IRIs.
 * - Doesn't support rdf:Seq shorthand syntax
 * - Doesn't support rdf:Collection shorthand syntax
 *
 * @author zozlak
 */
class RdfXmlParser implements iParser, iQuadIterator {

    const RDF_ROOT             = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#RDF';
    const RDF_ABOUT            = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#about';
    const RDF_DATATYPE         = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#datatype';
    const RDF_RESOURCE         = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#resource';
    const RDF_NODEID           = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#nodeID';
    const RDF_ID               = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#ID';
    const RDF_DESCRIPTION      = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#Description';
    const RDF_PARSETYPE        = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#parseType';
    const RDF_ABOUTEACHPREFIX  = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#aboutEachPrefix';
    const RDF_ABOUTEACH        = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#aboutEach';
    const RDF_LI               = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#li';
    const RDF_COLLELPREFIX     = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#_';
    const PARSETYPE_RESOURCE   = 'Resource';
    const PARSETYPE_LITERAL    = 'Literal';
    const PARSETYPE_COLLECTION = 'Collection';
    const XML_BASE             = 'http://www.w3.org/XML/1998/namespacebase';
    const XML_LANG             = 'http://www.w3.org/XML/1998/namespacelang';
    const CHUNK_SIZE           = 1000000;

    /**
     * 
     * @var array<string>
     */
    private static array $skipAttributes = [
        self::RDF_ABOUT,
        self::RDF_ID,
        self::RDF_NODEID,
        self::RDF_RESOURCE,
        self::RDF_DATATYPE,
        self::RDF_PARSETYPE,
        self::XML_LANG,
    ];

    /**
     * 
     * @var array<string>
     */
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
    private ?int $key = null;

    /**
     * 
     * @var array<string, iNamedNode>
     */
    private array $elementIds;

    /**
     * 
     * @var array<string, array<string>>
     */
    private array $nmsp;

    /**
     * 
     * @var array<RdfXmlParserState>
     */
    private array $stack;
    private RdfXmlParserState $state;

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
        return current($this->triples) ?: throw new \OutOfBoundsException();
    }

    public function key(): int | null {
        return key($this->triples) === null ? null : $this->key;
    }

    public function next(): void {
        $this->key++;
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
        $this->nmsp       = [];
        $this->elementIds = [];
        $this->state      = new RdfXmlParserState();
        $this->stack      = [$this->state];
        $this->parseBaseUri($this->baseUriDefault);
        $this->parser     = xml_parser_create_ns('UTF-8', '');
        xml_parser_set_option($this->parser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($this->parser, XML_OPTION_SKIP_TAGSTART, 0);
        xml_parser_set_option($this->parser, XML_OPTION_SKIP_WHITE, 0);
        xml_set_element_handler($this->parser, fn($x, $y, $z) => $this->onElementStart($y, $z), fn($x, $y) => $this->onElementEnd($y));
        xml_set_character_data_handler($this->parser, fn($x, $y) => $this->onCData($y));
        xml_set_start_namespace_decl_handler($this->parser, fn($x, $y, $z) => $this->onNamespaceStart($x, $y, $z));
        xml_set_end_namespace_decl_handler($this->parser, fn($x, $y) => $this->onNamespaceEnd($x, $y));
        $this->triples    = [];
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
        $oldState      = $this->state;
        $this->state   = clone($oldState);
        $this->stack[] = $this->state;

        if (isset($attribs[RdfXmlParser::XML_LANG])) {
            $this->state->lang = $attribs[RdfXmlParser::XML_LANG];
        }
        $this->state->datatype = $this->resolveIri($attribs[RdfXmlParser::RDF_DATATYPE] ?? null);

        switch ($oldState->state) {
            case RdfXmlParserState::STATE_ROOT:
                $name === self::RDF_ROOT ? $this->onRoot($attribs) : $this->onNode($name, $attribs);
                break;
            case RdfXmlParserState::STATE_NODE:
                $this->onNode($name, $attribs);
                break;
            case RdfXmlParserState::STATE_PREDICATE:
                $this->onPredicate($name, $attribs);
                break;
            case RdfXmlParserState::STATE_VALUE:
                $this->onNode($name, $attribs);
                $this->state->isCDataPredicate = false;
                $this->state->isCollection     = false;
                break;
            case RdfXmlParserState::STATE_XMLLITERAL:
                $this->onXmlLiteralElement($name, $attribs);
                break;
            default:
                throw new RdfIoException("Unknown parser state " . $this->state->state);
        }

        //echo "START " . $oldState->state . "=>" . $this->state->state . " $name (" . $this->state->literalValueDepth . ")\n";
    }

    /**
     * 
     * @param array<string, string> $attributes
     * @return void
     */
    private function onRoot(array &$attributes): void {
        $this->state->state = RdfXmlParserState::STATE_NODE;
        if (isset($attributes[self::XML_BASE])) {
            $this->parseBaseUri($attributes[self::XML_BASE]);
        }
    }

    /**
     * 
     * @param string $tag
     * @param array<string, string> $attributes
     * @return void
     */
    private function onNode(string $tag, array &$attributes): void {
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
        $this->state->subject = $subject;

        // type as tag
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-typed-nodes
        if ($tag !== self::RDF_DESCRIPTION) {
            $this->addTriple($subject, RDF::RDF_TYPE, $tag);
        }

        // predicates & values as attributes
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-property-attributes
        $attrToProcess = array_diff(array_keys($attributes), self::$skipAttributes);
        foreach ($attrToProcess as $attr) {
            $this->addTriple($subject, (string) $attr, $attributes[$attr], $this->state->lang, $this->state->datatype ?? '');
        }

        if ($this->state->isCollection) {
            $prevState            = $this->stack[count($this->stack) - 2];
            $collSubject          = $this->dataFactory->blankNode();
            $this->addTriple($prevState->subject, $this->state->predicate, $collSubject);
            $this->addTriple($collSubject, RDF::RDF_FIRST, $subject);
            $prevState->subject   = $collSubject;
            $prevState->predicate = $this->dataFactory->namedNode(RDF::RDF_REST);
        } elseif ($this->state->state === RdfXmlParserState::STATE_VALUE) {
            $prevState = $this->stack[count($this->stack) - 2];
            $this->addTriple($prevState->subject, $this->state->predicate, $subject);
        }

        // change the state
        $this->state->state = RdfXmlParserState::STATE_PREDICATE;
    }

    /**
     * 
     * @param string $tag
     * @param array<string, string> $attributes
     * @return void
     */
    private function onPredicate(string $tag, array &$attributes): void {
        $this->state->state             = RdfXmlParserState::STATE_VALUE;
        // https://www.w3.org/TR/rdf-syntax-grammar/#emptyPropertyElt
        $this->state->isCDataPredicate  = count(array_diff(array_keys($attributes), self::$literalAttributes)) === 0;
        $this->state->literalValue      = '';
        $this->state->literalValueDepth = 0;
        $this->state->predicate         = $this->dataFactory->namedNode($tag);
        $parseType                      = $attributes[self::RDF_PARSETYPE] ?? '';
        $subjectTmp                     = null;
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-parsetype-Collection
        if ($parseType === self::PARSETYPE_COLLECTION) {
            $this->state->isCollection = true;
        }
        // rdf:li to rdf:_n promotion
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-list-elements
        if ($tag === self::RDF_LI) {
            $prevState              = $this->stack[count($this->stack) - 2] ?? throw new RdfIoException('Empty stack');
            $this->state->predicate = $this->dataFactory->namedNode(self::RDF_COLLELPREFIX . $prevState->sequenceNo);
            $prevState->sequenceNo++;
        }

        if (isset($attributes[self::RDF_RESOURCE])) {
            // rdf:resource attribute
            $subjectTmp = $this->dataFactory->namedNode($this->resolveIri($attributes[self::RDF_RESOURCE]) ?? '');
            $this->addTriple(null, $this->state->predicate, $subjectTmp);
        } elseif (isset($attributes[self::RDF_NODEID])) {
            // rdf:nodeID attribute
            $subjectTmp = $this->dataFactory->blankNode($attributes[self::RDF_NODEID]);
            $this->addTriple(null, $this->state->predicate, $subjectTmp);
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
                $this->addTriple($subjectTmp, (string) $attr, $attributes[$attr], $this->state->lang, $this->state->datatype ?? '');
            }
        }

        // implicit blank node due to parseType="Resource"
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-parsetype-resource
        if ($parseType === self::PARSETYPE_RESOURCE) {
            $blankNode            = $this->dataFactory->blankNode();
            $this->addTriple(null, $tag, $blankNode);
            $this->state          = $this->state->withState(RdfXmlParserState::STATE_PREDICATE)->withSubject($blankNode);
            $this->state->subject = $blankNode;
            $this->stack[]        = $this->state;
        }

        // XML literal value due to parseType="Literal"
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-XML-literals
        if ($parseType === self::PARSETYPE_LITERAL) {
            $this->state->state = RdfXmlParserState::STATE_XMLLITERAL;
        }

        // reification
        // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-reifying
        if (isset($attributes[self::RDF_ID])) {
            $this->state->reifyAs = $this->handleElementId($attributes[self::RDF_ID]);
        }
    }

    private function onElementEnd(string $name): void {
        /* @var $oldState RdfXmlParserState */
        $oldState                      = array_pop($this->stack) ?: throw new RdfIoException('Empty states stack');
        $this->state                   = end($this->stack) ?: throw new RdfIoException('Empty states stack');
        $this->state->isCDataPredicate = $this->state->isCDataPredicate && $oldState->isCDataPredicate;

        if ($oldState->state === RdfXmlParserState::STATE_VALUE && $oldState->isCDataPredicate === true) {
            $this->addTriple(null, $oldState->predicate, $oldState->literalValue ?? '', $oldState->lang, $oldState->datatype ?? '', $oldState->reifyAs);
        } elseif ($oldState->state === RdfXmlParserState::STATE_XMLLITERAL) {
            // literal XML
            // https://www.w3.org/TR/rdf-syntax-grammar/#section-Syntax-XML-literals
            if ($oldState->literalValueDepth === 0) {
                $this->addTriple($oldState->subject, $oldState->predicate, $oldState->literalValue ?? '', '', RDF::RDF_XML_LITERAL);
            } else {
                $this->state->literalValue = $oldState->literalValue . "</" . $this->shorten($name) . ">";
            }
        }

        if ($oldState->isCollection) {
            $this->addTriple($oldState->subject, RDF::RDF_REST, RDF::RDF_NIL);
        }

        //echo "END $oldState->state=>" . $this->state->state . " $name ($oldState->isCDataPredicate,$oldState->literalValueDepth)\n";
    }

    private function onNamespaceStart(XMLParser $parser, string $prefix,
                                      string $uri): void {
        if (!isset($this->nmsp[$prefix])) {
            $this->nmsp[$prefix] = [];
        }
        $this->nmsp[$prefix][] = $uri;
    }

    private function onNamespaceEnd(XMLParser $parser, string $prefix): void {
        array_pop($this->nmsp[$prefix]);
    }

    private function onCData(string $data): void {
        if ($this->state->state === RdfXmlParserState::STATE_VALUE && $this->state->isCDataPredicate !== false || $this->state->state === RdfXmlParserState::STATE_XMLLITERAL) {
            $this->state->isCDataPredicate = true;
            $this->state->literalValue     .= $data;
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
                               string $lang = null, string $datatype = null,
                               iBlankNode | iNamedNode | null $reifyAs = null): void {
        $df = $this->dataFactory;

        $subject = $subject ?? $this->state->subject;
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
        if (!empty($reifyAs)) {
            $this->triples[] = $df->quad($reifyAs, $df->namedNode(RDF::RDF_SUBJECT), $subject);
            $this->triples[] = $df->quad($reifyAs, $df->namedNode(RDF::RDF_PREDICATE), $predicate);
            $this->triples[] = $df->quad($reifyAs, $df->namedNode(RDF::RDF_OBJECT), $object);
            $this->triples[] = $df->quad($reifyAs, $df->namedNode(RDF::RDF_TYPE), $df->namedNode(RDF::RDF_STATEMENT));
        }
    }

    /**
     * 
     * @param string $name
     * @param array<string, string> $attributes
     * @return void
     */
    private function onXmlLiteralElement(string $name, array &$attributes): void {
        $name                      = $this->shorten($name);
        $this->state->literalValue .= "<$name";
        if ($this->state->literalValueDepth === 0) {
            foreach ($this->nmsp as $alias => $prefix) {
                $this->state->literalValue .= ' xmlns:' . $alias . '="' . htmlspecialchars(end($prefix) ?: '', ENT_XML1, 'UTF-8') . '"';
            }
        }
        foreach ($attributes as $k => $v) {
            $this->state->literalValue .= ' ' . $this->shorten($k) . '="' . htmlspecialchars($v, ENT_XML1, 'UTF-8') . '"';
        }
        $this->state->literalValue .= ">";
        $this->state->literalValueDepth++;
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
        $bu                 = parse_url($baseUri) ?: [];
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
