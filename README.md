# quickRdfIo

[![Latest Stable Version](https://poser.pugx.org/sweetrdf/quick-rdf-io/v/stable)](https://packagist.org/packages/sweetrdf/quick-rdf-io)
![Build status](https://github.com/sweetrdf/quickRdfIo/workflows/phpunit/badge.svg?branch=master)
[![Coverage Status](https://coveralls.io/repos/github/sweetrdf/quickRdfIo/badge.svg?branch=master)](https://coveralls.io/github/sweetrdf/quickRdfIo?branch=master)
[![License](https://poser.pugx.org/sweetrdf/quick-rdf-io/license)](https://packagist.org/packages/sweetrdf/quick-rdf-io)

Collection of RDF parsers and serializers implementing the https://github.com/sweetrdf/rdfInterface interface.

Originally developed for the [quickRdf](https://github.com/sweetrdf/quickRdf) library.

## Supported formats

| format     | read/write | class                          | implementation       | streaming[1] |
|------------|------------|--------------------------------|----------------------|--------------|
| n-triples  | rw         | NQuadsParser, NQuadsSerializer | own                  | yes          |
| n-triples* | rw         | NQuadsParser, NQuadsSerializer | own                  | yes          |
| n-quads    | rw         | NQuadsParser, NQuadsSerializer | own                  | yes          |
| n-quads*   | rw         | NQuadsParser, NQuadsSerializer | own                  | yes          |
| rdf-xml    | rw         | RdfXmlParser, RdfXmlSerializer | own                  | yes          |
| turtle     | rw         | TriGParser, TurtleSerializer   | pietercolpaert/hardf | yes          |
| TriG       | r          | TriGParser                     | pietercolpaert/hardf | yes          |
| JsonLD     | rw         | JsonLdParser, JsonLdSerializer | ml/json-ld           | no           |
| JsonLD[2]  | w          | JsonLdStreamSerializer         | own[3]               | yes          |

[1] A streaming parser/serializer doesn't materialize the whole dataset in memory which assures constant (and low) memory footprint.
    (this feature applies only to the parser/serializer - see the section on memory usage below)
[2] Use the `jsonld-stream` value for the `$format` parameter of the `\quickRdfIo\Util::serialize()` to use this serializer.
[3] Outputs data only in the extremely flattened Json-LD but works in a streaming mode.

## Installation

* Obtain the [Composer](https://getcomposer.org)
* Run `composer require sweetrdf/quick-rdf-io`

## Automatically generated documentation

https://sweetrdf.github.io/quickRdfIo/namespaces/quickrdfio.html

It's very incomplete but better than nothing.\
[RdfInterface](https://github.com/sweetrdf/rdfInterface/) and [ml/json-ld](https://github.com/lanthaler/JsonLD) documentation is included.

## Usage

Remark - there are calls to two other libraries in examples
[sweetrdf/quick-rdf](https://github.com/sweetrdf/quickRdf) and [sweetrdf/term-templates](https://github.com/sweetrdf/termTemplates).
You may install them with `composer require sweetrdf/quick-rdf` and `composer require sweetrdf/term-templates`.

### Basic parsing

Just use `\quickRdfIo\Util::parse($input, $dataFactory, $format, $baseUri)`, where: 

* `$input` can be (almost) "anything containing RDF"
  (an RDF string, a path to a file, an URL, an opened resource (result of `fopen()`), a PSR-7 response or a PSR-7 StreamInterface object).
* `$dataFactory` is an object implementing the `\rdfInterface\DataFactory` interface, e.g. `new \quickRdf\DataFactory()`.
* `$format` is an **optional** explicit RDF format indication for handling rare situtations when the format can't be autodetected.
* `$baseUri` is an **optional** baseURI value (for some kind of `$input` values it can be autodected).

```php
include 'vendor/autoload.php';

// create a DataFactory - it's needed by all parsers
// (DataFactory implementation comes from other package, here sweetrdf/quick-rdf)
$dataFactory = new \quickRdf\DataFactory();

// parse a file
$iterator = \quickRdfIo\Util::parse('tests/files/quadsPositive.nq', $dataFactory);
foreach ($iterator as $i) echo "$i\n";
// parse a remote file (with format autodetection as github wrongly reports text/html)
$url = 'https://github.com/sweetrdf/quickRdfIo/raw/master/tests/files/spec2.10.rdf';
$iterator = \quickRdfIo\Util::parse($url, $dataFactory);
foreach ($iterator as $i) echo "$i\n";
// parse a PSR-7 response (format recognized from the response content-type header)
$url = 'https://www.w3.org/2000/10/rdf-tests/RDF-Model-Syntax_1.0/ms_7.2_1.rdf';
$client = new \GuzzleHttp\Client();
$request = new \GuzzleHttp\Psr7\Request('GET', $url);
$response = $client->send($request);
$iterator = \quickRdfIo\Util::parse($response, $dataFactory);
foreach ($iterator as $i) echo "$i\n";
// parse a string containing RDF with format autodetection
$rdf = file_get_contents('https://www.w3.org/2000/10/rdf-tests/RDF-Model-Syntax_1.0/ms_7.2_1.rdf');
$iterator = \quickRdfIo\Util::parse($rdf, $dataFactory);
foreach ($iterator as $i) echo "$i\n";
// parse an PHP stream
$stream = fopen('tests/files/quadsPositive.nq', 'r');
$iterator = \quickRdfIo\Util::parse($stream, $dataFactory);
fclose($stream);

// in most cases you will populate a Dataset with parsed triples/quads
// (note that a Dataset implementation comes from other package, e.g. sweetrdf/quick-rdf)
$dataset = new \quickRdf\Dataset();
$url = 'https://github.com/sweetrdf/quickRdfIo/raw/master/tests/files/spec2.10.rdf';
$dataset->add(\quickRdfIo\Util::parse($url, $dataFactory));
echo $dataset;
```

### Basic serialization

```php
include 'vendor/autoload.php';

$iterator = ...some \rdfInterface\QuadIterator, e.g. one from parsing examples...

// serialize to file in text/turtle format
\quickRdfIo\Util::serialize($iterator, 'turtle', 'myFile.ttl');
// serialize to string
echo \quickRdfIo\Util::serialize($iterator, 'turtle');

// use given namespace aliases when serializing to turtle
$nmsp = new \rdfHelpers\RdfNamespace();
$nmsp->add('http://purl.org/dc/terms/', 'dc');
$nmsp->add('http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf');
echo \quickRdfIo\Util::serialize($iterator, 'turtle', null, $nmsp);
```

### Basic conversion

```php
include 'vendor/autoload.php';
// create a DataFactory - it's needed by all parsers
// (note that DataFactory implementation comes from other package, e.g. sweetrdf/quick-rdf)
$dataFactory = new \quickRdf\DataFactory();

// or any other example from the "Basic parsing" section above
$iterator = \quickRdfIo\Util::parse('tests/files/puzzle4d_100k.nt', $dataFactory);
// or any other example from the "Basic serialization" section above
\quickRdfIo\Util::serialize($iterator, 'rdf', 'output.rdf');
```

### Basic filtering without a Dataset

It's worth noting that basic triples/quads filtering can be done in a memory efficient way without usage of a Dataset implementation.

Let's say we want to copy all triples with the `https://vocabs.acdh.oeaw.ac.at/schema#hasIdentifier` predicate
from the `test/files/puzzle4d_100k.nt` n-triples file into a `ids.ttl` turtle file.

A typical approach would be to load data into a Dataset, filter them there and finally serialize the Dataset:

```php
include 'vendor/autoload.php';
$dataFactory = new \quickRdf\DataFactory();
$t = microtime(true);

// parse input into a Dataset
$iterator = \quickRdfIo\Util::parse('tests/files/puzzle4d_100k.nt', $dataFactory);
$dataset = new \quickRdf\Dataset();
$dataset->add($iterator);

// filter out non-matching triples
$template = new \termTemplates\QuadTemplate(null, $dataFactory->namedNode('https://vocabs.acdh.oeaw.ac.at/schema#hasIdentifier'), null);
$dataset->deleteExcept($template);

// serialize
\quickRdfIo\Util::serialize($dataset, 'turtle', 'ids.ttl');

print_r([
    'time [s]' => microtime(true) - $t,
    'memory [MB]' => (int) (memory_get_peak_usage(true) / 1024 / 1024),
]);
// 4.4s, 125 MB of RAM
```

but it can be also done by using a "filtering generator" instead of the Dataset.
With this approach we avoid materializing the whole dataset in memory which should both reduce memory footprint and speed things up a little:

```php
include 'vendor/autoload.php';
$dataFactory = new \quickRdf\DataFactory();
$t = microtime(true);

// prepare input generator
$iterator = \quickRdfIo\Util::parse('tests/files/puzzle4d_100k.nt', $dataFactory);

// create a generator performing the filtering
$template = new \termTemplates\QuadTemplate(null, $dataFactory->namedNode('https://vocabs.acdh.oeaw.ac.at/schema#hasIdentifier'), null);
$filter = function($iter, $tmpl) {
    foreach ($iter as $quad) {
        if ($tmpl->equals($quad)) {
            yield $quad;
        }
    }
};
// wrap it into something implementing \rdfInterface\QuadIterator for types compatibility
$wrapper = new \rdfHelpers\GenericQuadIterator($filter($iterator, $template));

// serialize our filtering generator
\quickRdfIo\Util::serialize($wrapper, 'turtle', 'ids.ttl');

print_r([
    'time [s]' => microtime(true) - $t,
    'memory [MB]' => (int) (memory_get_peak_usage(true) / 1024 / 1024),
]);
// 2.7s, 51 MB of RAM
```

Results are better but the memory footprint is still surprisingly high.
This is because of the DataFactory implementation we've used and performance optimizations it's applying
(which admitedly in our scenario only slow things down).
We can can optimize further by using as dumb as possible DataFactory implementation
(for that we need another package - `sweetrdf/simple-rdf`):

```php
include 'vendor/autoload.php';
$dataFactory = new \simpleRdf\DataFactory();
$t = microtime(true);

// prepare input generator
$iterator = \quickRdfIo\Util::parse('tests/files/puzzle4d_100k.nt', $dataFactory);

// create a generator performing the filtering
$template = new \termTemplates\QuadTemplate(null, $dataFactory->namedNode('https://vocabs.acdh.oeaw.ac.at/schema#hasIdentifier'), null);
$filter = function($iter, $tmpl) {
    foreach ($iter as $quad) {
        if ($tmpl->equals($quad)) {
            yield $quad;
        }
    }
};
// wrap it into something implementing \rdfInterface\QuadIterator for types compatibility
$wrapper = new \rdfHelpers\GenericQuadIterator($filter($iterator, $template));

// serialize our filtering generator
\quickRdfIo\Util::serialize($wrapper, 'turtle', 'ids.ttl');

print_r([
    'time [s]' => microtime(true) - $t,
    'memory [MB]' => (int) (memory_get_peak_usage(true) / 1024 / 1024),
]);
// 1.9s, 2 MB of RAM
```

As we can see the optimized implementation is 2.3 times faster and has 60 times lower memory footprint that a Dataset-based one.

Notes:

* Check the [sweetrdf/term-templates](https://github.com/sweetrdf/termTemplates) library for more classes allowing to easily match triples/quads
  fulfilling given conditions.
* This approach is not limited to filtering. Simple triples/quads modifications can be applied similar way
  (just adjust the "filtering generator" `foreach` loop body).

### Manual parser/serializer instantiation

It's of course possible to instantiate particular parser/serializer explicitly.

This is the only option to fine-tune parser/serializer configuration, e.g.:

* Create a strict n-triples parser
  ```php
  $parser = new \quickRdfIo\NQuadsParser($dataFactory, true, \quickRdfIo\NQuadsParser::MODE_TRIPLES);
  ```
* Create a JsonLD serializer applying compacting with a context read from a given file and producing pretty-printed JSON:
  ```php
  $serializer = new \quickRdfIo\JsonLdSerializer(
      'http://baseUri', 
      \quickRdfIo\JsonLdSerializer::TRANSFORM_COMPACT, 
      JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
      'context.jsonld'
  );
  ```

Be aware that parsing/serialization with the manually created parser/serializer instance requires a little more code.

Compare

```php
include 'vendor/autoload.php';
$data = ...data read from somewhere...

// using \quickRdfIo\Util::serialize()
\quickRdfIo\Util::serialize($data, 'jsonld', 'output.jsonld');

// using manually instantiated serializer
$serializer = new \quickRdfIo\JsonLdSerializer();
$output = fopen('output.jsonld', 'w');
$serializer->serialize($data, $output);
fclose($output);
```
