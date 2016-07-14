<?php

namespace PhpPdg\SystemDependence;

use PHPCfg\Op\Stmt\ClassMethod;
use PHPCfg\Op\Stmt\Function_;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PhpParser\ParserFactory;
use PhpPdg\CfgBridge\Parser\FileParserInterface;
use PhpPdg\AstBridge\Parser\WrappedParser as AstWrappedParser;
use PhpPdg\CfgBridge\Parser\WrappedParser as CfgWrappedParser;
use PhpPdg\Graph\FactoryInterface as GraphFactoryInterface;
use PhpPdg\ProgramDependence\FactoryInterface as PdgFactoryInterface;
use PhpPdg\ProgramDependence\Node\OpNode;
use PhpPdg\SystemDependence\Node\FuncNode;
use PHPTypes\State;
use PHPTypes\Type;
use PHPTypes\TypeReconstructor;
use PhpPdg\Graph\Factory as GraphFactory;
use PhpPdg\ProgramDependence\Factory as PdgFactory;
use PhpPdg\SystemDependence\Factory as SdgFactory;

class Factory implements FactoryInterface {
	/** @var GraphFactoryInterface  */
	private $graph_factory;
	/** @var FileParserInterface  */
	private $cfg_parser;
	/** @var PdgFactoryInterface  */
	private $pdg_factory;
	/** @var  TypeReconstructor */
	private $type_reconstructor;

	public function __construct(GraphFactoryInterface $graph_factory, FileParserInterface $cfg_parser, PdgFactoryInterface $pdg_factory) {
		$this->graph_factory = $graph_factory;
		$this->cfg_parser = $cfg_parser;
		$this->pdg_factory = $pdg_factory;
		$this->type_reconstructor = new TypeReconstructor();
	}

	public static function createDefault() {
		$graph_factory = new GraphFactory();
		return new SdgFactory($graph_factory, new CfgWrappedParser((new AstWrappedParser((new ParserFactory())->create(ParserFactory::PREFER_PHP7)))), PdgFactory::createDefault($graph_factory));
	}

	public function create($systempath) {
		$sdg = $this->graph_factory->create();
		$system = new System($sdg);

		/** @var FuncNode[]|\SplObjectStorage $pdg_func_lookup */
		$pdg_func_lookup = new \SplObjectStorage();
		$cfg_scripts = [];
		/** @var \SplFileInfo $fileinfo */
		foreach (new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($systempath)), "/.*\\.php$/i") as $fileinfo) {
			$filename = $fileinfo->getRealPath();
			$cfg_scripts[] = $cfg_script = $this->cfg_parser->parse($filename);

			$pdg_func = $this->pdg_factory->create($cfg_script->main, $filename);
			$system->scripts[$filename] = $pdg_func;
			$func_node = new FuncNode($pdg_func);
			$system->sdg->addNode($func_node);
			$pdg_func_lookup[$cfg_script->main] = $func_node;

			foreach ($cfg_script->functions as $cfg_func) {
				$pdg_func = $this->pdg_factory->create($cfg_func, $filename);
				$scoped_name = $cfg_func->getScopedName();
				if ($cfg_func->class !== null) {
					$system->methods[$scoped_name] = $pdg_func;
				} else if (strpos($cfg_func->name, '{anonymous}#') === 0) {
					$system->closures[$scoped_name] = $pdg_func;
				} else {
					$system->functions[$scoped_name] = $pdg_func;
				}
				$func_node = new FuncNode($pdg_func);
				$system->sdg->addNode($func_node);
				$pdg_func_lookup[$cfg_func] = $func_node;
			}
		}

		$state = new State($cfg_scripts);
		$this->type_reconstructor->resolve($state);

		// link function calls to their functions
		foreach ($state->funcCalls as $funcCallPair) {
			list($func_call, $containing_cfg_func) = $funcCallPair;
			$call_node = new OpNode($func_call);
			$system->sdg->addNode($call_node);
			assert(isset($pdg_func_lookup[$containing_cfg_func]));
			$system->sdg->addEdge($pdg_func_lookup[$containing_cfg_func], $call_node, [
				'type' => 'contains'
			]);
			if ($func_call->name instanceof Literal) {
				$name = strtolower($func_call->name->value);
				if (isset($state->functionLookup[$name]) === true) {
					/** @var Function_ $cfg_function */
					foreach ($state->functionLookup[$name] as $cfg_function) {
						$cfg_func = $cfg_function->func;
						assert(isset($pdg_func_lookup[$cfg_func]));
						$system->sdg->addEdge($call_node, $pdg_func_lookup[$cfg_func], [
							'type' => 'call'
						]);
					}
				}
			}
		}

		foreach ($state->nsFuncCalls as $nsFuncCallPair) {
			list($ns_func_call, $containing_cfg_func) = $nsFuncCallPair;
			$call_node = new OpNode($ns_func_call);
			$system->sdg->addNode($call_node);
			assert(isset($pdg_func_lookup[$containing_cfg_func]));
			$system->sdg->addEdge($pdg_func_lookup[$containing_cfg_func], $call_node, [
				'type' => 'contains'
			]);
			assert($ns_func_call->nsName instanceof Literal); // should always be the case, as otherwise it would be a normal func call
			$cfg_functions = null;
			$nsName = strtolower($ns_func_call->nsName->value);
			if (isset($state->functionLookup[$nsName]) === true) {
				$cfg_functions = $state->functionLookup[$nsName];
			} else {
				assert($ns_func_call->name instanceof Literal);
				$name = strtolower($ns_func_call->name->value);
				if (isset($state->functionLookup[$name]) === true) {
					$cfg_functions = $state->functionLookup[$name];
				}
			}

			if ($cfg_functions !== null) {
				/** @var Function_ $cfg_function */
				foreach ($cfg_functions as $cfg_function) {
					$cfg_func = $cfg_function->func;
					assert(isset($pdg_func_lookup[$cfg_func]));
					$system->sdg->addEdge($call_node, $pdg_func_lookup[$cfg_func], [
						'type' => 'call'
					]);
				}
			}
		}

		foreach ($state->methodCalls as $methodCallPair) {
			list($method_call, $containing_cfg_func) = $methodCallPair;
			$call_node = new OpNode($method_call);
			$system->sdg->addNode($call_node);
			assert(isset($pdg_func_lookup[$containing_cfg_func]));
			$system->sdg->addEdge($pdg_func_lookup[$containing_cfg_func], $call_node, [
				'type' => 'contains'
			]);

			if ($method_call->name instanceof Literal) {
				$name = strtolower($method_call->name->value);
				$var_type = $method_call->var->type;
				if ($var_type->type === Type::TYPE_OBJECT) {
					$class_name = strtolower($var_type->userType);
					$cfg_methods = $this->resolveClassMethods($state, $class_name, $name);

					/** @var ClassMethod $cfg_method */
					foreach ($cfg_methods as $cfg_method) {
						$cfg_func = $cfg_method->func;
						assert(isset($pdg_func_lookup[$cfg_func]));
						$system->sdg->addEdge($call_node, $pdg_func_lookup[$cfg_func], [
							'type' => 'call'
						]);
					}
				}
			}
		}

		foreach ($state->staticCalls as $staticCallPair) {
			list($static_call, $containing_cfg_func) = $staticCallPair;
			$call_node = new OpNode($static_call);
			$system->sdg->addNode($call_node);
			assert(isset($pdg_func_lookup[$containing_cfg_func]));
			$system->sdg->addEdge($pdg_func_lookup[$containing_cfg_func], $call_node, [
				'type' => 'contains'
			]);

			if ($static_call->name instanceof Literal) {
				if ($static_call->class instanceof Literal) {
					$class_name = strtolower($static_call->class->value);
				} else {
					$class_name = $this->resolveClassNameFromType($static_call->class->type);
				}
				if ($class_name !== null) {
					$name = strtolower($static_call->name->value);
					$cfg_methods = $this->resolveClassMethods($state, $class_name, $name);
					/** @var ClassMethod $cfg_method */
					foreach ($cfg_methods as $cfg_method) {
						$cfg_func = $cfg_method->func;
						assert(isset($pdg_func_lookup[$cfg_func]));
						$system->sdg->addEdge($call_node, $pdg_func_lookup[$cfg_func], [
							'type' => 'call'
						]);
					}
				}
			}
		}

		return $system;
	}

	/**
	 * @param Type $type
	 * @return string|null
	 */
	private function resolveClassNameFromType(Type $type) {
		if ($type->type === Type::TYPE_OBJECT) {
			return strtolower($type->userType);
		}
		return null;
	}

	/**
	 * @param State $state
	 * @param string $class_name
	 * @param string $method_name
	 * @return ClassMethod[]
	 */
	private function resolveClassMethods(State $state, $class_name, $method_name) {
		$methods = [];
		if (isset($state->classResolves[$class_name]) === true) {
			foreach ($state->classResolves[$class_name] as $class) {
				foreach ($class->stmts->children as $op) {
					if ($op instanceof ClassMethod && strtolower($op->func->name) === $method_name) {
						$methods[] = $op;
					}
				}
			}
		}
		return $methods;
	}
}