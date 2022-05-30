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

use Psr\Http\Message\StreamInterface;
use rdfInterface\QuadIterator as iQuadIterator;
use rdfInterface\RdfNamespace as iRdfNamespace;
use rdfInterface\Quad as iQuad;
use rdfInterface\Literal as iLiteral;
use rdfInterface\NamedNode as iNamedNode;
use rdfInterface\BlankNode as iBlankNode;
use rdfInterface\Term as iTerm;
use rdfInterface\DefaultGraph as iDefaultGraph;
use zozlak\RdfConstants as RDF;

/**
 * A steaming JsonLD serializer. Generates output in the flatten JsonLD format
 * and does it in a greedy way (meaning subjects/predicates/values are acumulated
 * within graph/subject/predicate only if adjacent triples share the same
 * graph/subject/predicate). On the brigh side it's fast and has minimal memory 
 * footprint.
 *
 * To use this parser with the `\quickRdfIo\Util::serialize()` use the special
 * `jsonld-stream` value as `$format`.
 * 
 * @author zozlak
 */
class JsonLdStreamSerializer implements \rdfInterface\Serializer {

    const MODE_TRIPLES     = 1;
    const MODE_GRAPH       = 2;
    const DEFAULT_GRAPH_ID = '_:defaultGraph';
    use TmpStreamSerializerTrait;

    private int $mode;
    private iTerm $prevGraph;
    private iTerm $prevSubject;
    private iTerm $prevPredicate;
    private bool $firstValue;

    public function __construct(int $mode = self::MODE_GRAPH) {
        if (!in_array($mode, [self::MODE_TRIPLES, self::MODE_GRAPH])) {
            throw new RdfIoException("Wrong mode");
        }
        $this->mode = $mode;
    }

    /**
     * 
     * @param resource | StreamInterface $output output to serialize to
     * @param iQuadIterator $graph data to serialize
     * @param iRdfNamespace|null $nmsp parameter required for the `\rdfInterface\Serializer`
     *   interface compatibility but not being used.
     * @return void
     */
    public function serializeStream($output, iQuadIterator $graph,
                                    iRdfNamespace | null $nmsp = null): void {
        if (is_resource($output)) {
            $output = new ResourceWrapper($output);
        }
        if (!($output instanceof StreamInterface)) {
            throw new RdfIoException("Output has to be a resource or " . StreamInterface::class . " object");
        }

        unset($this->prevGraph);
        unset($this->prevSubject);
        unset($this->prevPredicate);

        $output->write('[');
        foreach ($graph as $i) {
            if ($this->mode === self::MODE_GRAPH) {
                $this->processGraph($output, $i->getGraph());
            }
            $this->processSubject($output, $i->getSubject());
            $this->processPredicate($output, $i->getPredicate());
            $this->processObject($output, $i->getObject());
        }
        $end = '';
        $end .= isset($this->prevPredicate) ? ']' : '';
        $end .= isset($this->prevSubject) ? '}' : '';
        $end .= isset($this->prevGraph) ? ']}' : '';
        $end .= ']';
        $output->write($end);
    }

    private function processGraph(StreamInterface $output,
                                  iDefaultGraph | iNamedNode | iBlankNode $graph): void {
        $graphUri = $graph instanceof iDefaultGraph ? self::DEFAULT_GRAPH_ID : $graph->getValue();
        if (!isset($this->prevGraph)) {
            $output->write('{' . $this->serializeId($graphUri) . ',"@graph":[');
            unset($this->prevSubject);
        } elseif (!$this->prevGraph->equals($graph)) {
            $end = '';
            $end .= isset($this->prevPredicate) ? ']' : '';
            $end .= isset($this->prevSubject) ? '}' : '';
            $output->write($end . ']},{' . $this->serializeId($graphUri) . ',"@graph":[');
            unset($this->prevSubject);
        }
        $this->prevGraph = $graph;
    }

    private function processSubject(StreamInterface $output,
                                    iNamedNode | iBlankNode $subject): void {
        if (!isset($this->prevSubject)) {
            $output->write('{' . $this->serializeId($subject));
            unset($this->prevPredicate);
        } elseif (!$this->prevSubject->equals($subject)) {
            $end = isset($this->prevPredicate) ? ']' : '';
            $output->write($end . '},{' . $this->serializeId($subject));
            unset($this->prevPredicate);
        }
        $this->prevSubject = $subject;
    }

    private function processPredicate(StreamInterface $output,
                                      iNamedNode $predicate): void {
        if (!isset($this->prevPredicate) || !$this->prevPredicate->equals($predicate)) {
            $coma             = !isset($this->prevPredicate) ? ',' : '],';
            $output->write($coma . json_encode($predicate->getValue(), JSON_UNESCAPED_SLASHES) . ':[');
            $this->firstValue = true;
        }
        $this->prevPredicate = $predicate;
    }

    private function processObject(StreamInterface $output, iTerm $object): void {
        $coma             = $this->firstValue ? '' : ',';
        $output->write($coma . json_encode($this->serializeNode($object), JSON_UNESCAPED_SLASHES));
        $this->firstValue = false;
    }

    private function serializeId(string | iTerm $term): string {
        $value = is_string($term) ? $term : $term->getValue();
        return '"@id":' . json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    private function serializeNode(iTerm $node): mixed {
        if ($node instanceof iNamedNode || $node instanceof iBlankNode) {
            return ['@id' => $node->getValue()];
        } elseif ($node instanceof iLiteral) {
            $val = ['@value' => $node->getValue()];
            if (!empty($node->getLang())) {
                $val['@language'] = $node->getLang();
            } elseif ($node->getDatatype() !== RDF::XSD_STRING) {
                $val['@type'] = $node->getDatatype();
            }
            return $val;
        } elseif ($node instanceof iQuad) {
            $val                                    = $this->serializeNode($node->getSubject());
            $val[$node->getPredicate()->getValue()] = $this->serializeNode($node->getObject());
            return $val;
        } else {
            throw new RdfIoException("Can't serialize object of class " . get_class($node));
        }
    }
}
