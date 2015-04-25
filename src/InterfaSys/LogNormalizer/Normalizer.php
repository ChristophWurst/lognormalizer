<?php
/**
 * interfaSys - lognormalizer
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Olivier Paroz <dev-lognormalizer@interfasys.ch>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 *
 * @copyright Olivier Paroz 2015
 * @copyright Jordi Boggiano 2014-2015
 */

namespace InterfaSys\LogNormalizer;

/**
 * Converts any variable to a String
 *
 * @package InterfaSys\LogNormalizer
 */
class Normalizer {

	/**
	 * @type string
	 */
	const SIMPLE_DATE = "Y-m-d H:i:s";

	/**
	 * @type int
	 */
	private $maxObjectDepth;

	/**
	 * @type int
	 */
	private $maxArrayItems;

	/**
	 * @type \DateTime
	 */
	private $dateFormat;

	/**
	 * @param int $maxObjectDepth
	 * @param int $maxArrayItems
	 * @param null|\DateTime $dateFormat
	 */
	public function __construct($maxObjectDepth = 2, $maxArrayItems = 20, $dateFormat = null) {
		$this->maxObjectDepth = $maxObjectDepth;
		$this->maxArrayItems = $maxArrayItems;
		if ($dateFormat) {
			$this->dateFormat = $dateFormat;
		} else {
			$this->dateFormat = static::SIMPLE_DATE;
		}
	}

	/**
	 * Normalises the variable, JSON encodes it if needed and cleans up the result
	 *
	 * @todo: could maybe do a better job removing slashes
	 *
	 * @param array $data
	 *
	 * @return string|null
	 */
	public function format(&$data) {
		$data = $this->normalize($data);
		if (!is_string($data)) {
			$data = @json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			// Removing null byte and double slashes from object properties
			$data = str_replace(['\\u0000', '\\\\'], ["", "\\"], $data);
		}

		return $data;
	}

	/**
	 * Converts Objects, Arrays, Dates and Exceptions to a String or a single Array
	 *
	 * @param $data
	 * @param int $depth
	 *
	 * @return string|array
	 */
	public function normalize($data, $depth = 0) {
		$scalar = $this->normalizeScalar($data);
		if (!is_array($scalar)) {
			return $scalar;
		}
		$decisionArray = [
			$this->normalizeTraversable($data, $depth),
			$this->normalizeDate($data),
			$this->normalizeObject($data, $depth),
			$this->normalizeResource($data),
		];

		foreach ($decisionArray as $dataType) {
			if ($dataType !== null) {
				return $dataType;
			}
		}

		return '[unknown(' . gettype($data) . ')]';
	}

	/**
	 * Returns various, filtered, scalar elements
	 *
	 * We're returning an array here to detect failure because null is a scalar and so is false
	 *
	 * @param $data
	 *
	 * @return mixed
	 */
	private function normalizeScalar($data) {
		if (null === $data || is_scalar($data)) {
			/*// utf8_encode only works for Latin1 so we rely on mbstring
			if (is_string($data)) {
				$data = mb_convert_encoding($data, "UTF-8");
			}*/

			return $data;
		}

		return [];
	}

	/**
	 * Returns an array containing normalized elements
	 *
	 * @param $data
	 * @param int $depth
	 *
	 * @return array|null
	 */
	private function normalizeTraversable($data, $depth = 0) {
		if (is_array($data) || $data instanceof \Traversable) {
			return $this->normalizeTraversableElement($data, $depth);
		}

		return null;
	}

	/**
	 * Converts each element of a traversable variable to String
	 *
	 * @param $data
	 * @param int $depth
	 *
	 * @return array
	 */
	private function normalizeTraversableElement($data, $depth) {
		$maxArrayItems = $this->maxArrayItems;
		$count = 1;
		$normalized = [];
		foreach ($data as $key => $value) {
			if ($count++ >= $maxArrayItems) {
				$normalized['...'] =
					'Over ' . $maxArrayItems . ' items, aborting normalization';
				break;
			}
			$normalized[$key] = $this->normalize($value, $depth);
		}

		return $normalized;
	}

	/**
	 * Converts a date to String
	 *
	 * @param mixed $data
	 *
	 * @return null|string
	 */
	private function normalizeDate($data) {
		if ($data instanceof \DateTime) {
			return $data->format($this->dateFormat);
		}

		return null;
	}

	/**
	 * Converts an Object to String
	 *
	 * @param mixed $data
	 * @param int $depth
	 *
	 * @return array|null
	 */
	private function normalizeObject($data, $depth) {
		if (is_object($data)) {
			if ($data instanceof \Exception) {
				return $this->normalizeException($data);
			}
			// We don't need to go too deep in the recursion
			$maxObjectRecursion = $this->maxObjectDepth;
			$response = $data;
			$arrayObject = new \ArrayObject($data);
			$serializedObject = $arrayObject->getArrayCopy();
			if ($depth < $maxObjectRecursion) {
				$depth++;
				$response = $this->normalize($serializedObject, $depth);
			}

			// Don't convert to json here as we would double encode
			return [sprintf("[object] (%s)", get_class($data)), $response];
		}

		return null;
	}

	/**
	 * Converts an Exception to String
	 *
	 * @param \Exception $exception
	 *
	 * @return string[]
	 */
	private function normalizeException(\Exception $exception) {
		$data = [
			'class'   => get_class($exception),
			'message' => $exception->getMessage(),
			'code'    => $exception->getCode(),
			'file'    => $exception->getFile() . ':' . $exception->getLine(),
		];
		$trace = $exception->getTraceAsString();
		$data['trace'][] = $trace;

		$previous = $exception->getPrevious();
		if ($previous) {
			$data['previous'] = $this->normalizeException($previous);
		}

		return $data;
	}

	/**
	 * Converts a resource to a String
	 *
	 * @param $data
	 *
	 * @return string|null
	 */
	private function normalizeResource($data) {
		if (is_resource($data)) {
			return "[resource] " . substr((string)$data, 0, 40);
		}

		return null;
	}

}
