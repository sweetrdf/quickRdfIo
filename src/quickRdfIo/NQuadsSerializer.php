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
use rdfHelpers\NtriplesUtil;
use rdfInterface\Quad as iQuad;
use rdfInterface\DefaultGraph as iDefaultGraph;
use rdfInterface\QuadIterator as iQuadIterator;
use rdfInterface\RdfNamespace as iRdfNamespace;

/**
 * Serializes n-quads and n-quads-star.
 *
 * @author zozlak
 */
class NQuadsSerializer implements \rdfInterface\Serializer {

    use TmpStreamSerializerTrait;

    public function __construct() {
        
    }

    /**
     * 
     * @param resource | StreamInterface $output
     * @param iQuadIterator $graph
     * @param iRdfNamespace|null $nmsp
     * @return void
     */
    public function serializeStream($output, iQuadIterator $graph,
                                    ?iRdfNamespace $nmsp = null
    ): void {
        if (is_resource($output)) {
            $output = new ResourceWrapper($output);
        }
        if (!($output instanceof StreamInterface)) {
            throw new RdfIoException("Output has to be a resource or " . StreamInterface::class . " object");
        }

        foreach ($graph as $i) {
            $output->write($this->serializeQuad($i));
        }
    }

    private function serializeQuad(iQuad $quad, string $end = " .\n"): string {
        $subject = $quad->getSubject();
        if ($subject instanceof iQuad) {
            $subject = '<< ' . $this->serializeQuad($subject, '') . ' >>';
        } else {
            $subject = NtriplesUtil::serializeIri($subject);
        }

        $predicate = '<' . NtriplesUtil::escapeIri($quad->getPredicate()->getValue()) . '>';

        $object = $quad->getObject();
        if ($object instanceof iQuad) {
            $object = '<< ' . $this->serializeQuad($object, '') . ' >>';
        } else {
            $object = NtriplesUtil::serialize($object);
        }

        $graph = $quad->getGraph();
        if ($graph !== null && !($graph instanceof iDefaultGraph)) {
            $graph = NtriplesUtil::serializeIri($graph);
        } else {
            $graph = '';
        }

        return "$subject $predicate $object $graph$end";
    }
}
