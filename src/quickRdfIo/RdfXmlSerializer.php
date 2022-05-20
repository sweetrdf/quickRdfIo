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

use rdfInterface\QuadIterator as iQuadIterator;
use rdfInterface\RdfNamespace as iRdfNamespace;
use rdfInterface\Literal as iLiteral;
use rdfInterface\NamedNode as iNamedNode;
use rdfInterface\BlankNode as iBlankNode;
use rdfInterface\DefaultGraph as iDefaultGraph;
use zozlak\RdfConstants as RDF;

/**
 * Simple RDF-XML serializer. It is optimized for speed and low memory footprint.
 * This means it doesn't perform any preprocessing of the serialized data.
 * As a consequence:
 * 
 * - All namespaces which aren't passed to the serializeStream()/serialize()
 *   methods using the $nmsp parameter are just declared in-place in each XML
 *   tag in which they are needed.
 * - The tag representing the subject is reused only if subsequent triples
 *   being serialized share the same subject. For the sake of simplicity, speed
 *   and low memory usage this class doesn't try to reorder triples in a way
 *   each subject in the graph has only single XML tag representing it.
 * 
 * Other limitations:
 * - Only triples can be serialized as the RDF-XML serialization format is 
 *   defined only for the base RDF.
 * - This class naivly shortens predicate URIs splitting on the last hash, slash,
 *   star or semicolon. This doesn't guarantee generation of a proper XML 
 *   PrefixedName. The simplest corner case is just an URI ending with a number 
 *   like http://foo/123 which is shortened to ns:123 which is invalid in XML.
 *
 * @author zozlak
 */
class RdfXmlSerializer implements \rdfInterface\Serializer {

    use TmpStreamSerializerTrait;

    /**
     * 
     * @var array<string, string>
     */
    private array $prefixes;
    private bool $prettyPrint;

    /**
     * 
     * @param bool $prettyPrint should output XML be pretty-formatted?
     */
    public function __construct(bool $prettyPrint = true) {
        $this->prettyPrint = $prettyPrint;
    }

    /**
     * 
     * @param resource $output
     * @param iQuadIterator $graph
     * @param iRdfNamespace|null $nmsp
     * @return void
     * @throws RdfIoException
     */
    public function serializeStream($output, iQuadIterator $graph,
                                    ?iRdfNamespace $nmsp = null): void {
        $nl             = $this->prettyPrint ? "\n" : "";
        $ind1           = $this->prettyPrint ? "  " : "";
        $ind2           = $this->prettyPrint ? "    " : "";
        $this->prefixes = [];
        if ($nmsp !== null) {
            $tmp            = $nmsp->getAll();
            $this->prefixes = array_combine(array_values($tmp), array_keys($tmp));
        }

        fwrite($output, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        fwrite($output, '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"');
        foreach ($this->prefixes as $prefix => $alias) {
            fwrite($output, ' xmlns:' . $alias . '="' . $this->e($prefix) . '"');
        }
        fwrite($output, ">$nl");

        $prevSbj = null;
        foreach ($graph as $i) {
            /* @var $i \rdfInterface\Quad */
            $s = $i->getSubject();
            $p = $i->getPredicate();
            $o = $i->getObject();
            if (!($s instanceof iBlankNode || $s instanceof iNamedNode)) {
                throw new RdfIoException("Only triples with named node or blank node subjects can be serialized (" . $s::class . ")");
            }
            if (!($o instanceof iBlankNode || $o instanceof iNamedNode || $o instanceof iLiteral)) {
                throw new RdfIoException("Only triples with named node, blank node or literal objects can be serialized (" . $o::class . ")");
            }
            if (!$i->getGraph() instanceof iDefaultGraph) {
                throw new RdfIoException("Can't serialize quads");
            }

            if ($prevSbj === null || $s->equals($prevSbj) === false) {
                if ($prevSbj !== null) {
                    fwrite($output, "$ind1</rdf:Description>$nl");
                }
                $attr    = $s instanceof iBlankNode ? 'rdf:nodeID' : 'rdf:about';
                $val     = $s instanceof iBlankNode ? substr($s->getValue(), 2) : $s->getValue();
                fwrite($output, "$ind1<rdf:Description " . $attr . '="' . $this->e($val) . '">' . $nl);
                $prevSbj = $i->getSubject();
            }

            list($pTag, $pNmsp) = $this->shorten($p->getValue());
            if ($o instanceof iBlankNode) {
                fwrite($output, "$ind2<$pTag$pNmsp" . ' rdf:nodeID="' . $this->e(substr($o->getValue(), 2)) . '"/>');
            } elseif ($o instanceof iNamedNode) {
                fwrite($output, "$ind2<$pTag$pNmsp" . ' rdf:resource="' . $this->e($o->getValue()) . '"/>');
            } elseif ($o instanceof iLiteral) {
                $lang = $o->getLang();
                $dt   = $o->getDatatype();

                $xml = "$ind2<$pTag$pNmsp";
                if (!empty($lang)) {
                    $xml .= ' xml:lang="' . $this->e($lang) . '"';
                } elseif ($dt !== RDF::XSD_STRING) {
                    $xml .= ' rdf:datatype="' . $this->e($dt) . '"';
                }
                $xml .= '>' . $this->e($o->getValue()) . "</$pTag>";
                fwrite($output, $xml);
            }
            fwrite($output, $nl);
        }
        if ($prevSbj !== null) {
            fwrite($output, "$ind1</rdf:Description>$nl");
        }
        fwrite($output, "</rdf:RDF>");
        $this->prefixes = [];
    }

    /**
     * 
     * @param string $uri
     * @return array<string>
     */
    private function shorten(string $uri): array {
        $l      = strlen(preg_replace('`[#/*:"][^#/*:"]+$`', '', $uri) ?: '') + 1;
        $prefix = substr($uri, 0, $l);
        if (isset($this->prefixes[$prefix])) {
            return [$this->prefixes[$prefix] . ":" . substr($uri, $l), ''];
        }
        return ['ns:' . substr($uri, $l), ' xmlns:ns="' . $this->e($prefix) . '"'];
    }

    private function e(string $value): string {
        return htmlspecialchars($value, ENT_XML1, 'UTF-8');
    }
}
