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
use rdfInterface\DefaultGraph as iDefaultGraph;
use rdfInterface\Literal as iLiteral;
use rdfInterface\NamedNode as iNamedNode;
use rdfInterface\BlankNode as iBlankNode;
use rdfInterface\Term as iTerm;
use ML\JsonLD\JsonLD;
use ML\JsonLD\Document;
use ML\JsonLD\LanguageTaggedString;
use ML\JsonLD\TypedValue;
use ML\JsonLD\Node;
use zozlak\RdfConstants as RDF;

/**
 * A steaming JsonLD serializer. Generates output in the (extremely) flatten 
 * JsonLD format which is only suitable for being parsed (or framed) with 
 * a Json-LD parsing library. This drawback is compenstated by high speed and
 * minimal memory footprint.
 *
 * @author zozlak
 */
class JsonLdStreamSerializer implements \rdfInterface\Serializer {

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
                                    iRdfNamespace | null $nmsp = null): void {
        if (is_resource($output)) {
            $output = new ResourceWrapper($output);
        }
        if (!($output instanceof StreamInterface)) {
            throw new RdfIoException("Output has to be a resource or " . StreamInterface::class . " object");
        }

        $output->write('[');
        $coma = '';
        foreach ($graph as $i) {
            $output->write($coma . $this->serializeQuad($i));
            $coma = ',';
        }
        $output->write(']');
    }

    private function serializeQuad(iQuad $quad): string {
        $graph  = $quad->getGraph()->getValue();
        $output = [
            '@id'    => empty($graph) ? '_:defaultGraph' : $graph,
            '@graph' => $this->serializeNode($quad),
        ];
        return json_encode($output) ?: throw new RdfIoException("Failed to serialize quad $quad");
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
