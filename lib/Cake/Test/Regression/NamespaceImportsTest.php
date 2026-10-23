<?php
/**
 * Regression coverage for GitHub issue #1, without legacy class aliases or PHPUnit.
 * Run: php lib/Cake/Test/Regression/NamespaceImportsTest.php
 */

use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Event\CakeEvent;
use Cake\Network\CakeRequest;
use Cake\Network\CakeResponse;
use Cake\Routing\Filter\AssetDispatcher;
use Cake\Utility\Xml;

define('DS', DIRECTORY_SEPARATOR);
define('CAKE', dirname(__DIR__, 2) . DS);
define('APP', CAKE . 'Test' . DS . 'test_app' . DS);
require CAKE . 'basics.php';

spl_autoload_register(function ($class) {
	if (strpos($class, 'Cake\\') === 0) {
		$path = CAKE . str_replace('\\', DS, substr($class, 5)) . '.php';
		if (is_file($path)) {
			require $path;
		}
	}
});

Configure::write('App', array('encoding' => 'UTF-8', 'base' => ''));
App::build(array('View' => array(APP . 'View' . DS)), App::RESET);

function checkNamespaceImport($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$tests = array();
foreach (array('simplexml', 'simplexmlelement', 'domdocument') as $mode) {
	foreach (array('string', 'build array', 'fromArray') as $source) {
		$tests["XML $source as $mode"] = function () use ($source, $mode) {
			$options = array('return' => $mode);
			$value = 'test & <value>';
			$input = array('root' => array('item' => $value));
			if ($source === 'fromArray') {
				$xml = Xml::fromArray($input, $options);
			} elseif ($source === 'build array') {
				$xml = Xml::build($input, $options);
			} else {
				$xml = Xml::build('<root><item>test &amp; &lt;value&gt;</item></root>', $options);
			}
			if ($mode === 'domdocument') {
				checkNamespaceImport($xml instanceof DOMDocument, 'Expected global DOMDocument');
				$actual = $xml->getElementsByTagName('item')->item(0)->textContent;
			} else {
				checkNamespaceImport($xml instanceof SimpleXMLElement, 'Expected global SimpleXMLElement');
				$actual = (string)$xml->item;
			}
			checkNamespaceImport($actual === $value, 'XML text did not round trip');
		};
	}
}

foreach (array('test_theme' => true, 'missing_theme' => false) as $theme => $exists) {
	foreach (array('img/cake.power.gif' => true, 'img/missing-issue-1.gif' => false) as $asset => $assetExists) {
		$tests["Theme asset $theme/$asset"] = function () use ($theme, $exists, $asset, $assetExists) {
			$request = new CakeRequest('theme/' . $theme . '/' . $asset, false);
			$response = new class extends CakeResponse {
				public function send() {
					// Keep HTTP headers inside the response during this CLI test.
				}
			};
			$filter = new AssetDispatcher();
			$event = new CakeEvent('Dispatcher.beforeDispatch', $filter, compact('request', 'response'));
			$bufferLevel = ob_get_level();
			ob_start();
			try {
				$result = $filter->beforeDispatch($event);
				$output = ob_get_contents();
			} finally {
				while (ob_get_level() > $bufferLevel) {
					ob_end_clean();
				}
			}
			if ($exists && $assetExists) {
				$path = App::themePath($theme) . 'webroot' . DS . str_replace('/', DS, $asset);
				checkNamespaceImport($result === $response, 'Expected the asset response');
				checkNamespaceImport($event->isStopped(), 'Expected dispatch to stop');
				checkNamespaceImport($output === file_get_contents($path), 'Asset contents differ');
			} else {
				checkNamespaceImport($result === null, 'Expected missing asset to fall through');
				checkNamespaceImport(!$event->isStopped(), 'Missing asset stopped dispatch');
				checkNamespaceImport($output === '', 'Missing asset produced output');
			}
		};
	}
}

$failures = 0;
foreach ($tests as $name => $test) {
	try {
		$test();
		echo "PASS: $name\n";
	} catch (Throwable $e) {
		$failures++;
		fwrite(STDERR, "FAIL: $name: " . $e->getMessage() . "\n");
	}
}
exit($failures ? 1 : 0);
