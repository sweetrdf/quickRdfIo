# quickRdfIo

Collection of parsers and serializers implementing the https://github.com/zozlak/rdfInterface interface.

Originally developed for the [quickRdf](https://github.com/zozlak/quickRdf) library.

Quite quick and dirty at the moment.

## Installation

* Obtain the [Composer](https://getcomposer.org)
* Run `composer require zozlak/quick-rdf-io`

## Usage

```
include 'vendor/autoload.php';

// parse turle/ntriples/nquad file
$parser = new quickRdfIo\TriGParser();
$instream = fopen('pathToTurtleFile', 'r');
foreach($parser->parseStream($stream) as $quad) {
   echo "$quad\n";
}
fclose($instream);

// convert to nquads/ntriples
$instream = fopen('pathToTurtleFile', 'r');
$iterator = $parser->parseStream($stream);
$serializer = new quickRdfIo\NQuadsSerializer();
$outstream = fopen('pathToOutputTurtleFile', 'w');
$serializer->serialiseStream($stream, $iterator);
fclose($outstream);
fclose($instream);
```
