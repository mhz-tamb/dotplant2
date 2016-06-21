<?php
namespace app\modules\seo;

use app\components\BaseModule;
use app\modules\seo\handlers\AnalyticsHandler;
use app\modules\seo\models\Counter;
use app\modules\seo\models\Meta;
use app\models\Submission;
use Yii;
use yii\base\BootstrapInterface;
use yii\base\Event;
use yii\web\Application;
use yii\web\Controller;
use yii\web\View;

class SeoModule extends BaseModule implements BootstrapInterface
{
    const NO_REDIRECT = 0;
    const FROM_WWW = 1;
    const FROM_WITHOUT_WWW = 2;
    public $cacheConfig = [
        'metaCache' => [
            'name' => 'metas',
            'expire' => 86400,
        ],
        'counterCache' => [
            'name' => 'counters',
            'expire' => 86400,
        ],
        'robotsCache' => [
            'name' => 'robots',
            'expire' => 86400,
        ],
    ];
    public $include = [];
    public $mainPage = '';
    /**
     * @var int type of redirect from WWW or without WWW
     */
    public $redirectWWW = self::NO_REDIRECT;
    /**
     * @var bool if true redirect from url with trailing slash
     */
    public $redirectTrailingSlash = false;

    public $analytics = [
        'ecGoogle' => [
            'active' => 0,
            'currency' => AnalyticsHandler::CURRENCY_MAIN,
        ],
        'ecYandex' => [
            'active' => 0,
            'currency' => AnalyticsHandler::CURRENCY_MAIN,
        ],
    ];

    /**
     * @inheritdoc
     */
    public function bootstrap($app)
    {
        if (is_string($this->include)) {
            $this->include = explode(',', $this->include);
        }
        $app->on(
            Application::EVENT_BEFORE_REQUEST,
            function () use ($app) {
                if ($app->getModule('seo')->redirectWWW != self::NO_REDIRECT) {
                    self::redirectWWW();
                }
                if ($app->getModule('seo')->redirectTrailingSlash == 1) {
                    self::redirectSlash();
                }

                $app->getView()->on(View::EVENT_END_BODY, [Counter::className(), 'renderCounters'], $this->include);
            }
        );

        // Analytics
        $app->on(Application::EVENT_BEFORE_ACTION, [AnalyticsHandler::className(), 'handleBeforeAction']);
        $app->on(Application::EVENT_BEFORE_ACTION, [Meta::className(), 'registrationMeta']);

        $app->on(
            Application::EVENT_BEFORE_ACTION,
            function($event) {
                $request = Yii::$app->request;
                $session = Yii::$app->session;
                if (Yii::$app->user->isGuest &&
                    $session->get('visitor_referrer') === null &&
                    $request->getReferrer() !== null &&
                    $request->getServerName() == parse_url($request->getReferrer(), PHP_URL_HOST)
                ) {
                    $session->set('visitor_referrer', $request->getReferrer());
                    $session->set('visitor_landing', $request->getUrl());
                    $session->set('visit_start_date', time());
                }
            }
        );

        Event::on(
            Submission::className(),
            Submission::EVENT_BEFORE_INSERT,
            function($event) use ($app) {
                $session = Yii::$app->session;
                $model = $event->sender;

                $model->visitor_referrer = $session->get('visitor_referrer');
                $model->visitor_landing = $session->get('visitor_landing');
                $model->visit_start_date = $session->get('visit_start_date');
            }
        );
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'configurableModule' => [
                'class' => 'app\modules\config\behaviors\ConfigurableModuleBehavior',
                'configurationView' => '@app/modules/seo/views/configurable/_config',
                'configurableModel' => 'app\modules\seo\models\ConfigConfigurationModel',
            ]
        ];
    }

    /**
     * @return array prepared to dropdown list in configurable
     */
    public static function getRedirectTypes()
    {
        return [
            self::NO_REDIRECT => Yii::t('app', 'No redirect'),
            self::FROM_WWW => Yii::t('app', 'Redirect from WWW to without WWW'),
            self::FROM_WITHOUT_WWW => Yii::t('app', 'Redirect from without WWW to WWW'),
        ];
    }

    /**
     * If redirectWWW config make 301 redirect to www or not www domain
     */
    public static function redirectWWW()
    {
        $type = Yii::$app->getModule('seo')->redirectWWW;
        if ($type != self::NO_REDIRECT) {
            $readirArr = [
                self::FROM_WITHOUT_WWW => function () {
                    if (preg_match('#^(http|https):\/\/www\.#i', Yii::$app->request->hostInfo) === 0) {
                        Yii::$app->response->redirect(
                            str_replace('://', '://www.', Yii::$app->request->absoluteUrl),
                            301
                        );
                        Yii::$app->end();
                    }
                },
                self::FROM_WWW => function () {
                    if (preg_match('#^(http|https):\/\/www\.#i', Yii::$app->request->hostInfo) === 1) {
                        Yii::$app->response->redirect(
                            str_replace('://www.', '://', Yii::$app->request->absoluteUrl),
                            301
                        );
                        Yii::$app->end();
                    }
                },
            ];
            $readirArr[$type]();
        }
    }

    /**
     * Make redirect from url with trailing slash
     */
    public static function redirectSlash()
    {
        $redirUrl = preg_replace('#^(.*)/$#', '$1', Yii::$app->request->url);
        if (!empty($redirUrl) && $redirUrl !== Yii::$app->request->url) {
            Yii::$app->response->redirect($redirUrl, 301);
            Yii::$app->end();
        }
    }
}
