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
use ML\JsonLD\JsonLD;
use ML\JsonLD\Document;
use ML\JsonLD\LanguageTaggedString;
use ML\JsonLD\TypedValue;
use ML\JsonLD\Node;
use zozlak\RdfConstants as RDF;

/**
 * Thin wrapper providing RdfInterface\Serializer API for JSON-LD the parser
 * provided by the ml/json-ld library.
 *
 * Be aware the implementation provided by the ml/json-ld library is not really 
 * focused on performance. If you value speed and/or low memory footprint
 * consider using other serialization format.
 * 
 * @author zozlak
 */
class JsonLdSerializer implements \rdfInterface\Serializer {

    const TRANSFORM_NONE    = 0;
    const TRANSFORM_EXPAND  = 1;
    const TRANSFORM_FLATTEN = 2;
    const TRANSFORM_COMPACT = 3;

    private ?string $baseUri;
    private int $transform;
    private int $jsonEncodeFlags;
    private mixed $context;

    /**
     * 
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * 
     * @param string $baseUri
     * @param int $transform transformation to be applied before serializing to
     *   string. One of `JsonLdSerializer::TRANSFORM_NONE` (default), 
     *   `JsonLdSerializer::TRANSFORM_EXPAND`, `JsonLdSerializer::TRANSFORM_FLATTEN`
     *   or `JsonLdSerializer::TRANSFORM_COMPACT`.
     *   In case of `JsonLdSerializer::TRANSFORM_COMPACT` the `$context`
     *   parameter has to be provided.
     * @param int $jsonEncodeFlags flags to be passed to the `json_encode()` 
     *   call serializing output to a string - see https://www.php.net/manual/en/function.json-encode.php .
     * @param mixed $context context to be passed to `ML\JsonLD\JsonLD::compact()`
     *   or `ML\JsonLD\JsonLD::flatten()` when `$transform` equals
     *   `JsonLdSerializer::TRANSFORM_COMPACT` or `JsonLdSerializer::TRANSFORM_FLATTEN`,
     *   repectively.
     * @param array<string, mixed> $options options to be passed to the corresponding
     *   `ML\JsonLD\JsonLD` method (`expand()`, `compact()` or `flatten()`
     *   according to the `$transform` parameter value).
     * @see \ML\JsonLD\JsonLD::expand()
     * @see \ML\JsonLD\JsonLD::flatten()
     * @see \ML\JsonLD\JsonLD::compact()
     * @see \json_encode()
     */
    public function __construct(?string $baseUri = null,
                                int $transform = self::TRANSFORM_NONE,
                                int $jsonEncodeFlags = JSON_UNESCAPED_SLASHES,
                                mixed $context = null, array $options = []) {
        $this->baseUri         = $baseUri;
        $this->transform       = $transform;
        $this->jsonEncodeFlags = $jsonEncodeFlags;
        $this->context         = $context;
        $this->options         = $options;
    }

    /**
     * 
     * @param iQuadIterator $graph
     * @param iRdfNamespace|null $nmsp If passed, it's used for compacting the
     *   output. Unfortunately only property URIs can be compacted that way.
     * @return string
     * @throws RdfIoException
     */
    public function serialize(iQuadIterator $graph, ?iRdfNamespace $nmsp = null): string {
        $doc = new Document($this->baseUri);
        foreach ($graph as $quad) {
            /* @var $quad iQuad  */
            $graph = $quad->getGraph();
            if ($graph instanceof iDefaultGraph) {
                $graph = $doc->getGraph();
            } else {
                $graph = $graph->getValue();
                if ($doc->containsGraph($graph)) {
                    $graph = $doc->getGraph($graph);
                } else {
                    $graph = $doc->createGraph($graph);
                }
            }
            if ($graph === null) {
                throw new RdfIoException("Failed to create graph");
            }

            $sbj = $quad->getSubject();
            if (!($sbj instanceof iNamedNode || $sbj instanceof iBlankNode)) {
                throw new RdfIoException("Can serialize only blank node and named node subjects " . $sbj::class . " given");
            }
            $sbj = $sbj->getValue();
            if ($graph->containsNode($sbj)) {
                $sbj = $graph->getNode($sbj);
            } else {
                $sbj = $graph->createNode((string) $sbj, true);
            }
            if ($sbj === null) {
                throw new RdfIoException("Failed to create subject");
            }

            $pred = $quad->getPredicate()->getValue();
            $obj  = $quad->getObject();
            if ($obj instanceof iLiteral) {
                if (!empty($obj->getLang())) {
                    $obj = new LanguageTaggedString($obj->getValue(), $obj->getLang());
                } else {
                    $obj = new TypedValue($obj->getValue(), $obj->getDatatype());
                }
            } elseif ($obj instanceof iNamedNode || $obj instanceof iBlankNode) {
                $obj = $obj->getValue();
                if ($graph->containsNode($obj)) {
                    $obj = $graph->getNode($obj);
                } else {
                    $obj = $graph->createNode($obj, true);
                }
            } else {
                throw new RdfIoException("Can serialize only literal, blank node and named node objects " . $obj::class . " given");
            }

            if ((string) $pred === RDF::RDF_TYPE) {
                if (!($obj instanceof Node)) {
                    throw new RdfIoException("rdf:type predicate with not named node object");
                }
                $sbj->addType($obj);
            } else {
                $sbj->addPropertyValue($pred, $obj);
            }
        }
        $output = $doc->toJsonLd();
        switch ($this->transform) {
            case self::TRANSFORM_EXPAND:
                $output = JsonLD::expand($output, $this->options);
                break;
            case self::TRANSFORM_FLATTEN:
                $output = JsonLD::flatten($output, $this->context, $this->options);
                break;
            case self::TRANSFORM_COMPACT:
                $output = JsonLD::compact($output, $this->context, $this->options);
                break;
        }
        $context = $nmsp?->getAll();
        if (count($context) > 0) {
            $output = JsonLD::compact($output, $context, $this->options);
        }
        return json_encode($output, $this->jsonEncodeFlags) ?: throw new RdfIoException("Failed to serialize the data");
    }

    /**
     * 
     * @param resource | StreamInterface $output
     * @param iQuadIterator $graph
     * @param iRdfNamespace|null $nmsp unused but required for compatibility with
     *   the `\rdfInterface\Serializer`.
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

        $output->write($this->serialize($graph, $nmsp));
    }
}
