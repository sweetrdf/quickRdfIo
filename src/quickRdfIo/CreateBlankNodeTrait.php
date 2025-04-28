<?php

/*
 * The MIT License
 *
 * Copyright 2025 zozlak.
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

use rdfInterface\ParserInterface as iParser;
use rdfInterface\BlankNodeInterface as iBlankNode;
use rdfInterface\DataFactoryInterface as iDataFactory;

/**
 *
 * @author zozlak
 */
trait CreateBlankNodeTrait {

    static private $blankMap = [];
    private string $baseUri  = '';
    private iDataFactory $dataFactory;

    public function createBlankNode(string $iri): iBlankNode {
        if (str_starts_with($iri, '_:')) {
            $iri = substr($iri, 2);
        }
        $key = $this->baseUri . '/' . $iri;
        if (!isset(self::$blankMap[$key])) {
            if ($this->baseUri !== iParser::BLANK_NODES_PRESERVE) {
                $iri = null;
            }
            self::$blankMap[$key] = $this->dataFactory::blankNode($iri);
        }
        return self::$blankMap[$key];
    }
}
