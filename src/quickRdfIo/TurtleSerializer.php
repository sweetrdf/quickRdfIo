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

use zozlak\RdfConstants as RDF;
use quickRdf\RdfNamespace;

/**
 * Description of TurtleSerializer
 *
 * @author zozlak
 */
class TurtleSerializer implements \rdfInterface\Serializer {

    public function __construct() {
        
    }

    public function serialize(
        \rdfInterface\QuadIterator $graph,
        ?\rdfInterface\RdfNamespace $nmsp = null
    ): string {
        $output = '';
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new RdfIoException('Failed to convert input to stream');
        }
        $this->serializeStream($stream, $graph, $nmsp);
        $len = ftell($stream);
        if ($len === false) {
            throw new RdfIoException('Failed to seek in output streem');
        }
        rewind($stream);
        $output = fread($stream, $len);
        if ($output === false) {
            throw new RdfIoException('Failed to read from output streem');
        }
        fclose($stream);
        return $output;
    }

    public function serializeStream(
        $output, \rdfInterface\QuadIterator $graph,
        ?\rdfInterface\RdfNamespace $nmsp = null
    ): void {
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
            if ($object instanceof \rdfInterface\Literal) {
                $langtype = $object->getLang();
                if (empty($langtype)) {
                    $langtype = $object->getDatatype();
                    if ($langtype === RDF::XSD_STRING) {
                        $langtype = '';
                    }
                }
                $object = \pietercolpaert\hardf\Util::createLiteral((string) $object->getValue(), $langtype);
            } else {
                $object = (string) $object->getValue();
            }
            $graphIri = $i->getGraphIri();
            $graphIri = $graphIri->getValue() === $graphIri->getType() ? null : (string) $graphIri->getValue();
            $serializer->addTriple($subject, $predicate, $object, $graphIri);
            fwrite($output, $serializer->read());
        }
        fwrite($output, $serializer->end() ?? '');
    }
}
