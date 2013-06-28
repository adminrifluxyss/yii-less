<?php
/**
 * LessServerCompiler class file.
 * @author Christoffer Niska <ChristofferNiska@gmail.com>
 * @copyright Copyright &copy; Christoffer Niska 2013-
 * @copyright Copyright &copy; Sam Stenvall 2013-
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD License
 */

/**
 * Server-side LESS compiler.
 */
class LessServerCompiler extends LessCompiler
{
	// Compression methods.
	const COMPRESSION_WHITESPACE = 'whitespace';
	const COMPRESSION_YUI = 'yui';

	/**
	 * @var string the base path.
	 */
	public $basePath;
	/**
	 * @var string absolute path to the nodejs executable.
	 */
	public $nodePath;
	/**
	 * @var string absolute path to the compiler.
	 */
	public $compilerPath = 'lessc';
	/**
	 * @var boolean whether to force evaluation of imports.
	 */
	public $strictImports = false;
	/**
	 * @var string|boolean compression type. Valid values are "whitespace" and "yui".
	 * Set to false to disable compression.
	 */
	public $compression = false;
	/**
	 * @var integer|boolean parser optimization level. Valid values are 0, 1 and 2.
	 * Set to false to disable optimization.
	 */
	public $optimizationLevel = false;
	/**
	 * @var boolean whether to always compile files, even if they have not changed.
	 */
	public $forceCompile = false;

	/**
	 * Initializes the component.
	 * @throws CException if initialization fails.
	 */
	public function init()
	{
		parent::init();

		if ($this->compression !== false
				&& !in_array($this->compression, array(self::COMPRESSION_WHITESPACE, self::COMPRESSION_YUI)))
			throw new CException('Failed to initialize LESS compiler. Property compression must be either "whitespace" or "yui".');

		if ($this->optimizationLevel !== false && !in_array($this->optimizationLevel, array(0, 1, 2)))
			throw new CException('Failed to initialize LESS compiler. Property optimizationLevel must be 0, 1 or 2.');
	}

	/**
	 * Runs the compiler.
	 * @throws CException if an error occurred.
	 */
	public function run()
	{
		if (!isset($this->basePath))
			$this->basePath = Yii::getPathOfAlias('webroot');

		foreach ($this->files as $lessFile => $cssFile)
		{
			$lessPath = realpath($this->basePath . DIRECTORY_SEPARATOR . $lessFile);
			$cssPath = str_replace('/', DIRECTORY_SEPARATOR, $this->basePath . DIRECTORY_SEPARATOR . $cssFile);

			if ($this->needsCompilation($lessPath, $cssPath))
			{
				if (!is_readable($lessPath))
				{
					$errorPath = ($lessPath === false)? ($this->basePath . DIRECTORY_SEPARATOR . $lessFile) : $lessPath;
					throw new CException('Failed to compile LESS. Source path must be readable: "'.$errorPath.'".');
				}

				if (!file_exists($cssPath))
					touch($cssPath);

				if (!is_writable($cssPath))
					throw new CException('Failed to compile LESS. Destination path must be writable: "'.$cssPath.'".');

				$this->compileFile($lessPath, $cssPath);
			}

			echo CHtml::linkTag('stylesheet', 'text/css', Yii::app()->baseUrl . '/' . $cssFile);
		}
	}
	
	/**
	 * Checks whether we need to recompile the specified LESS file.
	 * @param string $lessPath the path to the LESS file
	 * @param string $cssPath the path to the CSS file it's compiled to
	 * @return boolean whether we need to recompile it
	 */
	private function needsCompilation($lessPath, $cssPath)
	{
		/**
		 * Checks whether $subject has been modified since $reference was
		 */
		$isNewer = function($subject, $reference) {
			return filemtime($subject) > filemtime($reference);
		};

		// Check for obvious cases
		if ($this->forceCompile || !file_exists($lessPath) 
				|| !file_exists($cssPath) || $isNewer($lessPath, $cssPath))
		{
			return true;
		}

		// Check if any imported file has changed
		$lessContent = file_get_contents($lessPath);
		preg_match_all('/(?<=@import)\s+"([^"]+)/im', $lessContent, $imports);

		foreach ($imports[1] as $import)
		{
			$importPath = realpath(dirname($lessPath).DIRECTORY_SEPARATOR.$import);

			if (file_exists($importPath) && $isNewer($importPath, $cssPath))
				return true;
		}

		return false;
	}

	/**
	 * Compiles the given LESS file into the given CSS.
	 * @param string $lessPath path to the less file.
	 * @param string $cssPath path to the css file.
	 * @throws CException if the compilation fails.
	 */
	protected function compileFile($lessPath, $cssPath)
	{
		$options = array();

		if ($this->strictImports === true)
			$options[] = '--strict-imports';

		if ($this->compression === self::COMPRESSION_WHITESPACE)
			$options[] = '--compress';
		else if ($this->compression === self::COMPRESSION_YUI)
			$options[] = '--yui-compress';

		if ($this->optimizationLevel !== false)
			$options[] = '-O' . $this->optimizationLevel;

		if (isset($this->rootPath))
			$options[] = '--rootpath ' . $this->rootPath;

		if ($this->relativeUrls === true)
			$options[] = '--relative-urls';

		$nodePath = $this->nodePath? '"' . $this->nodePath . '" ' : '';
		$command = $nodePath . '"' . $this->compilerPath . '" '
			. implode(' ', $options) . ' "' . $lessPath . '" "' . $cssPath . '"';

		$return = 0;
		$output = array();
		@exec($command, $output, $return);

		if ($return == 2)
			$return = '2: Write error';

		if ($return !== 0)
			throw new CException(
				'Failed to compile file "' . $lessPath . '" using command: ' . $command . '. Return was: [' . $return . '] ' . implode("\n", $output));
	}
}
