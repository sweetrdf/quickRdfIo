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
use ML\IRI\IRI;
use ML\JsonLD\JsonLD;
use ML\JsonLD\Quad as JsonLdQuad;
use ML\JsonLD\LanguageTaggedString;
use ML\JsonLD\TypedValue;
use rdfInterface\QuadIterator as iQuadIterator;
use rdfInterface\Parser as iParser;
use rdfInterface\Quad as iQuad;
use rdfInterface\DataFactory as iDataFactory;

/**
 * Thin wrapper providing RdfInterface\Parser API for JSON-LD the parser
 * provided by the ml/json-ld library.
 *
 * Doesn't provide stream parsing because the ml/json-ld library doesn't so
 * be carefull when parsing large inputs.
 * 
 * @author zozlak
 */
class JsonLdParser implements iParser, iQuadIterator {

    private iDataFactory $dataFactory;
    private ?string $baseUri = null;
    private ?iQuad $curQuad = null;

    /**
     * 
     * @var array<int, JsonLdQuad>
     */
    private array $quads;

    public function __construct(iDataFactory $dataFactory,
                                ?string $baseUri = null) {
        $this->dataFactory = $dataFactory;
        $this->baseUri     = $baseUri;
    }

    public function setBaseUri(?string $baseUri): void {
        $this->baseUri = $baseUri;
    }

    public function current(): iQuad | null {
        $df   = $this->dataFactory;
        $quad = current($this->quads);
        if ($quad === false) {
            return null;
        }
        if ($this->curQuad === null) {
            /* @var $quad JsonLdQuad */
            $sbj = $quad->getSubject();
            $sbj = $sbj->getScheme() === '_' ? $df->blankNode((string) $sbj) : $df->namedNode((string) $sbj);

            $pred = $df->namedNode((string) $quad->getProperty());

            $obj = $quad->getObject();
            if ($obj instanceof LanguageTaggedString) {
                $obj = $df->literal($obj->getValue(), $obj->getLanguage());
            } elseif ($obj instanceof TypedValue) {
                $obj = $df->literal($obj->getValue(), null, $obj->getType());
            } elseif ($obj instanceof IRI) {
                $obj = $obj->getScheme() === '_' ? $df->blankNode((string) $obj) : $df->namedNode((string) $obj);
            } else {
                throw new RdfIoException("Unsupported object class " . $obj::class);
            }

            $graph = $quad->getGraph();
            if (!empty((string) $graph)) {
                $graph = $graph->getScheme() === '_' ? $df->blankNode((string) $graph) : $df->namedNode($graph);
            } else {
                $graph = $df->DefaultGraph();
            }

            $this->curQuad = $df->quad($sbj, $pred, $obj, $graph);
        }
        return $this->curQuad;
    }

    public function key(): int | null {
        return key($this->quads);
    }

    public function next(): void {
        next($this->quads);
        $this->curQuad = null;
    }

    public function parse(string $input): iQuadIterator {
        $this->quads = JsonLD::toRdf($input, ['base' => $this->baseUri]);
        return $this;
    }

    /**
     * 
     * @param resource | StreamInterface $input
     * @return iQuadIterator
     */
    public function parseStream($input): iQuadIterator {
        $input = is_resource($input) ? stream_get_contents($input) : $input->getContents();
        return $this->parse($input ?: '');
    }

    public function rewind(): void {
        reset($this->quads);
    }

    public function valid(): bool {
        return key($this->quads) !== null;
    }
}
