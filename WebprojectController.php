<?php

namespace backend\controllers;

use Yii;
use yii\data\ActiveDataProvider;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;
use yii\base\Exception;
use yii\imagine\Image;
use yii\helpers\Json;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use common\components\Util;
use common\models\Offer;
use common\models\Webproject;
use common\models\WebprojectSearch;
use common\models\WebprojectConfig;
use common\models\GeoRegion;
use common\models\GeoCity;
use common\models\CompanyImageWebprojectRelation;
use common\components\seranking\models\SerankingSite;
use common\components\seranking\models\SerankingSiteSeRelation;

/**
 * WebprojectController implements the CRUD actions for Webproject model.
 */
class WebprojectController extends BaseController
{	
	/////////////////////////////////////////////////////////////////////////////
	// Примеры использования компонента seranking в некоторых экшенах контроллера.
	// Остальной не относящийся к проблеме использования компонента код опущен.
	/////////////////////////////////////////////////////////////////////////////

    /**
     * Добавление web-проекта в seranking.ru
     *
     * @param integer $id Webproject identifier
     *
     * @return array
     */
    public function actionAddWebprojectToSeranking($id)
    {
        /** @var $seranking \common\components\seranking\SerankingAPI */
        $seranking = Yii::$app->seranking;

        /** @var $model Webproject */
        $model = $this->findModel($id);

        Yii::$app->getResponse()->format = Response::FORMAT_JSON;

        try {
            /**
             * Добавление сначала самого сайта.
             */
            $config = [
                'url' => $model->domain_name,
                'title' => $model->name,
                'depth' => 100,
                'subdomain_match' => 0,
                'exact_url' => 1,
                'manual_check_freq' => 'check_yandex_up',
                'auto_reports' => 1,
                'day_of_week' => 1
            ];

            $siteid = $seranking->addSite($config);
            $serankingSiteModel = new SerankingSite();
            $serankingSiteModel->webproject_id = $model->id;
            $serankingSiteModel->siteid = $siteid;
            $serankingSiteModel->setAttributes($config, false);
            if (!$serankingSiteModel->save()) {
                // удаляем сайт из seranking, если не можем сохранить модель на своей стороне
                $seranking->deleteSite($siteid);
                throw new \yii\db\Exception(
                    'Cannot save SerankingSite model for webproject_id = ' . $model->id .
                    ' and siteid = ' . $siteid
                );
            }

            /**
             * Добавление к сайту дефолтных поисковых систем.
             */
            $ses = [
                '339' => 'Москва', // Google Moscow
                '411~213' => 'Москва', // Yandex Moscow
                '403' => 'Россия' // Yahoo Russia
            ];
            // todo: нужна более детальная обработка статусов
            $status = $seranking->updateSiteSE($siteid, $ses);
            if ($status) {
                // массив с поисковиками, проиндексированный по id поисковика
                $sesArray = $seranking->getSearchEngines();
                // добавляем модели  SerankingSiteSeRelation в цикле
                foreach ($ses as $k => $v) {
                    $serankingSiteSERelationModel = new SerankingSiteSeRelation();
                    $serankingSiteSERelationModel->siteid = $siteid;
                    $regionName = ($v === '' ? null : $v);
                    if(preg_match('/^(\d+)~(\d+)$/', $k, $matches) && isset($matches[1], $matches[2])) {
                        list($engineId, $regionId) = [$matches[1], $matches[2]];
                    } else {
                        list($engineId, $regionId) = [$k, null];
                    }
                    $serankingSiteSERelationModel->engine_name = $sesArray[$engineId]['name'];
                    $serankingSiteSERelationModel->engine_id = $engineId;
                    $serankingSiteSERelationModel->region_id = $regionId;
                    $serankingSiteSERelationModel->region_name = $regionName;

                    if (!$serankingSiteSERelationModel->save()) {
                        // удаляем модель из seranking.ru, если не получилось добавить поисковик для него
                        $seranking->deleteSite($siteid);
                        throw new \yii\db\Exception(
                            'Cannot save SerankingSiteSeRelation model for siteid = ' . $siteid .
                            ' Possible validation errors: ' . Html::errorSummary($serankingSiteSERelationModel)
                        );
                    }
                }
            }

            return [
                'error' => false,
                'message' => 'Сайт успешно добавлен в систему seranking.ru!'
            ];
        } catch (Exception $e) {
            return [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Удаление web-проекта из seranking.ru
     *
     * @param integer $id Webproject identifier
     *
     * @return array
     */
    public function actionRemoveWebprojectFromSeranking($id)
    {
        /** @var $seranking \common\components\seranking\SerankingAPI */
        $seranking = Yii::$app->seranking;

        /** @var $model Webproject */
        $model = $this->findModel($id);

        Yii::$app->getResponse()->format = Response::FORMAT_JSON;

        try {
            $status = $seranking->deleteSite($model->serankingSiteRelation->siteid);
            if ($status) {
                SerankingSite::deleteAll(['=', 'siteid', $model->serankingSiteRelation->siteid]);
            }

            return [
                'error' => false,
                'message' => 'Сайт успешно удален из системы seranking.ru!'
            ];
        } catch (Exception $e) {
            return [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }
    }
}
