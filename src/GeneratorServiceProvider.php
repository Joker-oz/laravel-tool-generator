<?php

namespace OuZhou\LaravelToolGenerator;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use OuZhou\LaravelToolGenerator\Console\Commands\CommonTrait;
use OuZhou\LaravelToolGenerator\Console\Commands\ControllerCommand;
use OuZhou\LaravelToolGenerator\Console\Commands\ModelCommonClassGeneratorCommand;
use OuZhou\LaravelToolGenerator\Console\Commands\ModelCRUDCommand;
use OuZhou\LaravelToolGenerator\Tools\StaticClasses\JokerPaginator;

class GeneratorServiceProvider extends ServiceProvider
{
    use CommonTrait;
    /**
     * 服务提供者加是否延迟加载.
     *
     * @var bool
     */
    protected $defer = false; // 延迟加载服务

    /**
     * z注入标识
     */
    const JOKER_INJECT_TOKEN = 'e10adc3949ba59abbe56e057f20f883e';

    /**
     * 跨域注册--获取注册位置标识
     */
    const JOKER_MIDDLEWARE_KERNEL = 'protected $middleware = [';

    const JOKER_MIDDLEWARE_KERNEL_ROUTE = 'protected $routeMiddleware = [';

    /**
     * api响应注入controller--获取注册位置标识--注入包
     */
    const JOKER_API_RESPONSE_CONTROLLER_PACKAGE = 'namespace App\Http\Controllers;';

    /**
     * api响应注入controller--获取注册位置标识--名称
     */
    const JOKER_API_RESPONSE_CONTROLLER_NAME = 'use AuthorizesRequests,';

    // 生成模板根路径
    const COMMON_PACKAGE = 'app/Databases';

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        /******************添加生成model的常用命令****************/
        if ($this->app->runningInConsole()) {
            $this->commands([
                // 生成模型通用配置
                ModelCommonClassGeneratorCommand::class,
                // 单个模型crud配置
                ModelCRUDCommand::class,
                // 生成控制器
                ControllerCommand::class,
            ]);
        }
        /*******************直接生成model通用配置*******************/
        // 判断是否已经生成
        if (!is_dir(base_path(self::COMMON_PACKAGE))) {
			Artisan::call('ouzhou:generator');
		}


		/*********************向controller注入apiResponseInjector*************************/
		$data = file_get_contents(app_path('Http/Controllers/Controller.php'));
        if (false === strpos($data, self::JOKER_INJECT_TOKEN)) { // 是否已经注入，避免重复注入
            if (false !== strpos($data, self::JOKER_API_RESPONSE_CONTROLLER_NAME)) { // 检查是否版本更新，导致定位符不存在了
                self::jokerApiResponseInjectorController($data);
            }
        }

        /**********************发布配置文件****************************/
        // 跨域生成地址
        $enableCrossPath = config_path('jokerEnableCrossRequest.php');
        $this->publishes([
            __DIR__ . '\Configs\jokerEnableCrossRequest.php' => $enableCrossPath,
        ], 'config');
        // 判断是否重复发布
        if (!file_exists($enableCrossPath)) {
            Artisan::call('vendor:publish', [
                '--tag' => 'config', // 生成配置
            ]);
            echo "Success => Path: \"$enableCrossPath\"\t";
        }
        
        // auth认证方式
		$authPath = config_path('jokerAuth.php');
		$this->publishes([
			__DIR__ . '\Configs\jokerAuth.php' => $authPath,
		], 'config');
		// 判断是否重复发布
		if (!file_exists($authPath)) {
			Artisan::call('vendor:publish', [
				'--tag' => 'config', // 生成配置
			]);
			echo "Success => Path: \"$authPath\"\t";
		}

        /***********为跨域注入到Kernel.php的middleware数组中**************/
        $data = file_get_contents(app_path('Http/Kernel.php'));
        if (false === strpos($data, self::JOKER_INJECT_TOKEN)) { // 是否已经注入，避免重复注入
            self::jokerMiddlewareInjectKernel($data);
        }


        /********************判断.env配置文件是否存在--不存在就复制一份**********/
        file_exists(base_path('.env')) || copy(base_path('.env.example'), base_path('.env'));


        /********************判断.env文件中是不是已经存在校验码**********/
//        $data = file_get_contents(base_path('.env'));
//        /************为自定义登录验证注入配置到.env**************/
//        if (false === strpos($data, self::JOKER_INJECT_TOKEN)) {
//            self::jokerAuthInjectEnv();
//        }

        /***********将jokerAuth的拓展写入对应位置*************/
        self::jokerAuthSonTemplateInjectJokerAuthPackage();

        /***********将相关文件写入对应位置*************/
        self::writeFileToTruePath();
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        JokerPaginator::injectIntoBuilder();
//        $this->app->singleton('JokerAuth', function ($app) {
//            return new JokerAuth($app);
//        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        // 因为延迟加载 所以要定义 provides 函数 具体参考laravel 文档
        return [''];
    }

    /**
     * Function: jokerAuthInjectEnv
     * Notes: 将自定义的登录验证配置注入当前框架
     * User: Joker
     * Email: <jw.oz@outlook.com>
     * Date: 2019-09-17  16:39
     */
    private static function jokerAuthInjectEnv()
    {
        // 向文件加入JokerAuth的配置
        $code = <<< CODE
		
#校验码-@{token}
#登录方式--cookie
LOGIN_METHOD=cookie
#登录方式--token-header
#LOGIN_METHOD=token
CODE;
        // 替换唯一标识符
        $code = str_replace('@{token}', self::JOKER_INJECT_TOKEN, $code);
        // 执行追加
        file_put_contents(base_path('.env'), $code, FILE_APPEND);
        // 发布到通用配置里面
        file_put_contents(base_path('.env.example'), $code, FILE_APPEND);
    }

    /**
     * Function: jokerEnableCrossRequestInjectKernel
     * Notes: 跨域注入
     * User: Joker
     * Email: <jw.oz@outlook.com>
     * Date: 2019-09-28  20:27
     * @param $data
     */
    private static function jokerMiddlewareInjectKernel($data)
    {
        $code = <<< CODE
	// 注入标识：@{token}
	protected \$middleware = [
		\OuZhou\LaravelToolGenerator\Middleware\EnableCrossRequestMiddleware::class, // 跨域
		\Illuminate\Session\Middleware\StartSession::class, // api 与 web都支持session
CODE;
        // 替换唯一标识符
        $code = str_replace('@{token}', self::JOKER_INJECT_TOKEN, $code);
        $data = str_replace(self::JOKER_MIDDLEWARE_KERNEL, $code, $data);

        $code = <<< CODE
	// 注入标识：@{token}
	protected \$routeMiddleware = [
		'requestLog' => \OuZhou\LaravelToolGenerator\Middleware\RequestSystemLogMiddleware::class, // 请求的系统日志
		'isLogin' => \App\Http\Middleware\LoginMiddleware::class, // 登录认证
CODE;
        // 替换唯一标识符
        $code = str_replace('@{token}', self::JOKER_INJECT_TOKEN, $code);
        $data = str_replace(self::JOKER_MIDDLEWARE_KERNEL_ROUTE, $code, $data);


        // 重新写入文件
        file_put_contents(app_path('Http/Kernel.php'), $data);
    }

    /**
     * Function: jokerApiResponseInjectorController
     * Notes: 将自定义的api响应依赖加载到controller中
     * User: Joker
     * Email: <jw.oz@outlook.com>
     * Date: 2019-09-17  18:39
     * @param $data
     */
    private static function jokerApiResponseInjectorController($data)
    {
        // 引入的包替换
        $code1 = <<< CODE
// 注入标识：@{token}
namespace App\Http\Controllers;
	
use OuZhou\LaravelToolGenerator\Traits\JokerApiResponseInjector;
CODE;
        // 引入的类名称替换
        $code2 = <<<CODE
use AuthorizesRequests, JokerApiResponseInjector,
CODE;

        // 替换唯一标识符
        $code1 = str_replace('@{token}', self::JOKER_INJECT_TOKEN, $code1);
        // 替换包和名称
        $data = str_replace([
            self::JOKER_API_RESPONSE_CONTROLLER_PACKAGE,
            self::JOKER_API_RESPONSE_CONTROLLER_NAME
        ], [
            $code1, // 包
            $code2 // 名称
        ], $data);
        // 重新写入文件
        file_put_contents(app_path('Http/Controllers/Controller.php'), $data);

    }

    /**
     * Function: jokerAuthCookieTemplateInjectJokerAuthPackage
     * Notes: 将JokerAuth的cookie验证的拓展发布到指定位置
     * User: Joker
     * Email: <jw.oz@outlook.com>
     * Date: 2019-09-28  20:28
     */
    private static function jokerAuthSonTemplateInjectJokerAuthPackage()
    {
        // cookie写入
        $aimPath = app_path('Http/JokerAuth/AuthCookie.php');
        if (!file_exists($aimPath)) {
            $path = __DIR__ . '\Auth\cookieExtend.txt';
            $data = file_get_contents($path);
            self::save($aimPath, $data);
        }

        // token写入
        $aimPath = app_path('Http/JokerAuth/AuthToken.php');
        if (!file_exists($aimPath)) {
            $path = __DIR__ . '\Auth\tokenExtend.txt';
            $data = file_get_contents($path);
            self::save($aimPath, $data);
        }
    }

    /**
     * Function: writeFileToTruePath
     * Notes: 将相关文件写入对应的位置
     * User: Joker
     * Email: <jw.oz@outlook.com>
     * Date: 2019-09-28  21:38
     */
    private static function writeFileToTruePath()
    {
        // loginMiddleware 写入 app\http\middleware
        $aimPath = app_path('Http/Middleware/LoginMiddleware.php');
        if (!file_exists($aimPath)) {
            $path = __DIR__ . '\Middleware\LoginMiddleware.txt';
            $data = file_get_contents($path);
            self::save($aimPath, $data);
        }
        
        // jokerTool 写入App\Http\JokerTools
		$aimPath = app_path('Http/JokerTools/JokerTool.php');
		if (!file_exists($aimPath)) {
			$path = __DIR__ . '\Tools\StaticClasses\JokerTool.txt';
			$data = file_get_contents($path);
			self::save($aimPath, $data);
		}
	
		// jokerFileUploader 写入App\Http\JokerTools
		$aimPath = app_path('Http/JokerTools/JokerFileUploader.php');
		if (!file_exists($aimPath)) {
			$path = __DIR__ . '\Tools\StaticClasses\JokerFileUploader.txt';
			$data = file_get_contents($path);
			self::save($aimPath, $data);
		}
	
		// jokerLog 写入App\Http\JokerTools
		$aimPath = app_path('Http/JokerTools/JokerLog.php');
		if (!file_exists($aimPath)) {
			$path = __DIR__ . '\Tools\StaticClasses\JokerLog.txt';
			$data = file_get_contents($path);
			self::save($aimPath, $data);
		}
		
		// jokerLaravelGenerator 写入config
		$aimPath = base_path('config/jokerLaravelGenerator.php');
		if (!file_exists($aimPath)) {
			$path = __DIR__ . '\Configs\jokerLaravelGenerator.php';
			$data = file_get_contents($path);
			self::save($aimPath, $data);
		}
    }
}
