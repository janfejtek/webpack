<?php

declare(strict_types=1);

namespace Contributte\Webpack\DI;

use Contributte\Webpack\AssetLocator;
use Contributte\Webpack\AssetNameResolver;
use Contributte\Webpack\BasePath\BasePathProvider;
use Contributte\Webpack\BasePath\NetteHttpBasePathProvider;
use Contributte\Webpack\BuildDirectoryProvider;
use Contributte\Webpack\Debugging\WebpackPanel;
use Contributte\Webpack\DevServer\DevServer;
use Contributte\Webpack\DevServer\Http\CurlClient;
use Contributte\Webpack\Manifest\ManifestLoader;
use Contributte\Webpack\Manifest\Mapper\WebpackManifestPluginMapper;
use Contributte\Webpack\PublicPathProvider;
use Nette\Bridges\ApplicationLatte\ILatteFactory;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\FactoryDefinition;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Definitions\Statement;
use Nette\DI\MissingServiceException;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Tracy;

/**
 * @property-read array<string, mixed> $config
 */
final class WebpackExtension extends CompilerExtension
{
	private bool $debugMode;

	private bool $consoleMode;

	public function __construct(bool $debugMode, ?bool $consoleMode = null)
	{
		$this->debugMode = $debugMode;
		$this->consoleMode = $consoleMode ?? \PHP_SAPI === 'cli';
	}

	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'debugger' => Expect::bool($this->debugMode),
			'macros' => Expect::bool(\interface_exists(ILatteFactory::class)),
			'devServer' => Expect::structure([
				'enabled' => Expect::bool($this->debugMode),
				'url' => Expect::string()->nullable()->dynamic(),
				'publicUrl' => Expect::string()->nullable()->dynamic(),
				'timeout' => Expect::anyOf(Expect::float(), Expect::int())->default(0.1),
				'ignoredAssets' => Expect::listOf(Expect::string())->default([]),
			])->castTo('array')
				->assert(
					static fn (array $devServer): bool => !$devServer['enabled'] || $devServer['url'] !== null,
					"The 'webpack › devServer › url' expects to be string, null given."
				),
			'build' => Expect::structure([
				'directory' => Expect::string()->required(),
				'publicPath' => Expect::string()->required(),
			])->castTo('array'),
			'manifest' => Expect::structure([
				'name' => Expect::string()->nullable(),
				'optimize' => Expect::bool(!$this->debugMode && (!$this->consoleMode || (bool) \getenv('CONTRIBUTTE_WEBPACK_OPTIMIZE_MANIFEST'))),
				'mapper' => Expect::anyOf(Expect::string(), Expect::type(Statement::class))->default(WebpackManifestPluginMapper::class),
			])->castTo('array'),
		])->castTo('array');
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();

		$basePathProvider = $builder->addDefinition($this->prefix('pathProvider.basePathProvider'))
			->setType(BasePathProvider::class)
			->setFactory(NetteHttpBasePathProvider::class)
			->setAutowired(false);

		$builder->addDefinition($this->prefix('pathProvider'))
			->setFactory(PublicPathProvider::class, [$this->config['build']['publicPath'], $basePathProvider]);

		$builder->addDefinition($this->prefix('buildDirProvider'))
			->setFactory(BuildDirectoryProvider::class, [$this->config['build']['directory']]);

		$builder->addDefinition($this->prefix('devServer'))
			->setFactory(DevServer::class, [
				$this->config['devServer']['enabled'],
				$this->config['devServer']['url'] ?? '',
				$this->config['devServer']['publicUrl'],
				$this->config['devServer']['timeout'],
				new Statement(CurlClient::class),
			]);

		$assetLocator = $builder->addDefinition($this->prefix('assetLocator'))
			->setFactory(AssetLocator::class, [
				'ignoredAssetNames' => $this->config['devServer']['ignoredAssets'],
			]);

		$assetResolver = $this->setupAssetResolver($this->config);

		if ($this->config['debugger']) {
			$assetResolver->setAutowired(false);
			$builder->addDefinition($this->prefix('assetResolver.debug'))
				->setFactory(AssetNameResolver\DebuggerAwareAssetNameResolver::class, [$assetResolver]);
		}

		// latte macro
		if ($this->config['macros']) {
			try {
				$latteFactory = $builder->getDefinitionByType(ILatteFactory::class);
				\assert($latteFactory instanceof FactoryDefinition);

				$definition = $latteFactory->getResultDefinition();
				\assert($definition instanceof ServiceDefinition);

				$definition
					->addSetup('?->addProvider(?, ?)', ['@self', 'webpackAssetLocator', $assetLocator])
					->addSetup('?->onCompile[] = function ($engine) { Contributte\Webpack\Latte\WebpackMacros::install($engine->getCompiler()); }', ['@self']);
			} catch (MissingServiceException $e) {
				// ignore
			}
		}
	}

	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();

		if ($this->config['debugger'] && \interface_exists(Tracy\IBarPanel::class)) {
			$definition = $builder->getDefinition($this->prefix('pathProvider'));
			\assert($definition instanceof ServiceDefinition);

			$definition->addSetup('@Tracy\Bar::addPanel', [
				new Statement(WebpackPanel::class)
			]);
		}
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function setupAssetResolver(array $config): ServiceDefinition
	{
		$builder = $this->getContainerBuilder();

		$assetResolver = $builder->addDefinition($this->prefix('assetResolver'))
			->setType(AssetNameResolver\AssetNameResolverInterface::class);

		if ($config['manifest']['name'] !== null) {
			if (!$config['manifest']['optimize']) {
				$loader = $builder->addDefinition($this->prefix('manifestLoader'))
					->setFactory(ManifestLoader::class, [
						1 => new Statement($config['manifest']['mapper']),
					])
					->setAutowired(false);

				$assetResolver->setFactory(AssetNameResolver\ManifestAssetNameResolver::class, [
					$config['manifest']['name'],
					$loader
				]);
			} else {
				$devServerInstance = new DevServer(false, '', '', 0.0, new CurlClient());

				$mapperInstance = new $config['manifest']['mapper']();

				$directoryProviderInstance = new BuildDirectoryProvider($config['build']['directory'], $devServerInstance);
				$loaderInstance = new ManifestLoader($directoryProviderInstance, $mapperInstance);
				$manifestCache = $loaderInstance->loadManifest($config['manifest']['name']);

				$assetResolver->setFactory(AssetNameResolver\StaticAssetNameResolver::class, [$manifestCache]);

				// add dependency so that container is recompiled if manifest changes
				$manifestPath = $loaderInstance->getManifestPath($config['manifest']['name']);
				$this->compiler->addDependencies([$manifestPath]);
			}
		} else {
			$assetResolver->setFactory(AssetNameResolver\IdentityAssetNameResolver::class);
		}

		return $assetResolver;
	}
}
