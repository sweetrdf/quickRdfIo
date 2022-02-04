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
use rdfInterface\QuadIterator as iQuadIterator;
use rdfInterface\RdfNamespace as iRdfNamespace;
use quickRdf\RdfNamespace;

/**
 * Description of TurtleSerializer
 *
 * @author zozlak
 */
class TurtleSerializer implements \rdfInterface\Serializer {

    use TmpStreamSerializerTrait;

    public function __construct() {
        
    }

    public function serializeStream($output, iQuadIterator $graph,
                                    ?iRdfNamespace $nmsp = null): void {
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
            $graph = $i->getGraph();
            $graph = $graph instanceof \rdfInterface\DefaultGraph ? null : (string) $graph->getValue();
            $serializer->addTriple($subject, $predicate, $object, $graph);
            fwrite($output, $serializer->read());
        }
        fwrite($output, $serializer->end() ?? '');
    }
}
