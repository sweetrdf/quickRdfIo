<?php

/*
 * The MIT License
 *
 * Copyright 2021 zozlak.
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
use zozlak\RdfConstants as RDF;
use rdfInterface\Literal as iLiteral;
use rdfInterface\QuadIterator as iQuadIterator;
use rdfInterface\RdfNamespace as iRdfNamespace;
use rdfInterface\NamedNode as iNamedNode;
use rdfInterface\BlankNode as iBlankNode;
use rdfInterface\DefaultGraph as iDefaultGraph;
use quickRdf\RdfNamespace;

/**
 * Serializes to TriG and Turtle formats
 *
 * @author zozlak
 */
class TrigSerializer implements \rdfInterface\Serializer {

    const MODE_TURTLE = 1;
    const MODE_TRIG   = 2;
    use TmpStreamSerializerTrait;

    private int $mode;
    private bool $strict;

    /**
     * 
     * @param int $mode serialization mode `TrigSerializer::MODE_TRIG` or 
     *   `TrigSerializer::MODE_TURTLE`. The exact behavior depends on the 
     *   `$strict` parameter.
     * @param bool $strict if `$mode` equal `TrigSerializer::MODE_TURTLE` and
     *   a triple with graph not being a default graph is encountered and
     *   `$strict` equals `false`, then the graph URI is just skipped. Otherwise
     *   an exception is rised.
     */
    public function __construct(int $mode = self::MODE_TRIG,
                                bool $strict = false) {
        $this->mode   = $mode;
        $this->strict = $strict;
    }

    /**
     * 
     * @param resource | StreamInterface $output
     * @param iQuadIterator $graph
     * @param iRdfNamespace|null $nmsp
     * @return void
     */
    public function serializeStream($output, iQuadIterator $graph,
                                    ?iRdfNamespace $nmsp = null): void {
        if (is_resource($output)) {
            $output = new ResourceWrapper($output);
        }
        if (!($output instanceof StreamInterface)) {
            throw new RdfIoException("Output has to be a resource or " . StreamInterface::class . " object");
        }

        $nmsp       = $nmsp ?? new RdfNamespace();
        $serializer = new \pietercolpaert\hardf\TriGWriter(['format' => 'turtle']);
        if ($nmsp !== null) {
            foreach ($nmsp->getAll() as $alias => $prefix) {
                $serializer->addPrefix($alias, $prefix);
            }
        }
        foreach ($graph as $i) {
            /* @var $i \rdfInterface\Quad */
            $subject   = (string) $i->getSubject()->getValue();
            $predicate = (string) $i->getPredicate()->getValue();
            $object    = $i->getObject();
            if ($object instanceof iLiteral) {
                $langtype = $object->getLang();
                if (empty($langtype)) {
                    $langtype = $object->getDatatype();
                    if ($langtype === RDF::XSD_STRING) {
                        $langtype = '';
                    }
                }
                $object = \pietercolpaert\hardf\Util::createLiteral((string) $object->getValue(), $langtype);
            } elseif ($object instanceof iNamedNode || $object instanceof iBlankNode) {
                $object = (string) $object->getValue();
            } else {
                throw new RdfIoException("Can't serialize object of class " . get_class($object));
            }
            $graph        = $i->getGraph();
            $defaultGraph = $graph instanceof iDefaultGraph;
            if ($this->mode === self::MODE_TURTLE && !$defaultGraph) {
                if ($this->strict) {
                    throw new RdfIoException("Can't serialize non-default graphs in MODE_TURTLE");
                } else {
                    $graph = null;
                }
            } else {
                $graph = $defaultGraph ? null : $graph->getValue();
            }
            $serializer->addTriple($subject, $predicate, $object, $graph);
            $output->write($serializer->read());
        }
        $output->write($serializer->end() ?? '');
    }
}
