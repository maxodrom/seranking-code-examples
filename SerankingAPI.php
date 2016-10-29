<?php

namespace common\components\seranking;

use Yii;
use yii\base\Component;
use yii\base\InvalidParamException;
use yii\base\NotSupportedException;
use yii\helpers\FileHelper;
use yii\httpclient\Client;
use yii\validators\UrlValidator;
use yii\base\Exception;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;

/**
 * Class SerankingAPI
 *
 * @package common\components\seranking
 */
class SerankingAPI extends Component
{
    /**
     * @var string API URL.
     */
    private $_apiUrl = 'http://online.seranking.com/structure/clientapi/v2.php';
    /**
     * @var string Runtime component directory.
     */
    private $_runtimeDir;
    /**
     * @var string Path to token file.
     */
    private $_tokenFile;
    /**
     * @var string Token
     */
    private $_token;
    /**
     * @var bool Log in status
     */
    private $_isLoggedIn = false;
    /**
     * @var string Login (email)
     */
    public $login;
    /**
     * @var string md5() hash of password
     */
    public $passwordMd5Hash;
    /**
     * @var integer Время обновления для служебных файлов (default - сутки).
     */
    public $refreshTime = 86400;

    /**
     * @inheritdoc
     *
     * @throws \yii\base\Exception
     */
    public function init()
    {
        parent::init();
        if (null === $this->login) {
            throw new Exception(
                'Для корректной работы компонента необходимо указать пароль от сервиса seranking.ru'
            );
        }
        if (null === $this->passwordMd5Hash) {
            throw new Exception(
                'Для корректной работы компонента необходимо указать md5() хэш пароля от seranking.ru'
            );
        }

        // Initialize runtime data directory
        $runtimeDir = FileHelper::normalizePath(Yii::getAlias('@runtime/seranking'));
        if (!is_dir($runtimeDir)) {
            if (!FileHelper::createDirectory($runtimeDir)) {
                throw new Exception('Cannot initialize runtime directory: ' . $runtimeDir);
            }
        }
        $this->_runtimeDir = $runtimeDir;

        // Path to token file
        if ($this->_tokenFile === null) {
            $tokenFile = $this->getRuntimeDir() . DIRECTORY_SEPARATOR . 'token';
            $this->_tokenFile = $tokenFile;
        }
        if (file_exists($this->getTokenFile())) {
            $this->_setToken(file_get_contents($this->getTokenFile()));
        }

        // Try to log on seranking.ru using default component settings from configuration
        if (null === $this->_getToken()) {
            $result = $this->login();
            if ($result instanceof self) {
                $token = $this->_getToken();
                // Save token for further purposes...
                if (!file_put_contents($this->getTokenFile(), $token)) {
                    throw new Exception(
                        'Cannot write token to file: ' . $this->getTokenFile()
                    );
                }
            }
        }
    }

    /**
     * @return string
     */
    public function getRuntimeDir()
    {
        return $this->_runtimeDir;
    }

    /**
     * @return string Path to token file.
     */
    public function getTokenFile()
    {
        return $this->_tokenFile;
    }

    /**
     * Gets API URL.
     *
     * @return null|string API URL or null if it was not set
     */
    public function getApiUrl()
    {
        return $this->_apiUrl;
    }

    /**
     * Sets main API URL.
     *
     * @param string $url Valid URL
     *
     * @return $this
     */
    public function setApiUrl($url)
    {
        $validator = new UrlValidator();
        if ($validator->validate($url)) {
            $this->_apiUrl = $url;

            return $this;
        } else {
            throw new InvalidParamException("$url is not a valid URL.");
        }
    }

    /**
     * Returns current login property.
     *
     * @return string|null
     */
    public function getLogin()
    {
        return $this->login;
    }

    /**
     * Returns current md5 password hash.
     *
     * @return string|null
     */
    public function getPasswordMd5Hash()
    {
        return $this->passwordMd5Hash;
    }

    /**
     * Получение текущего токена.
     * Возвращает null, если токен не установлен.
     *
     * @return string|null
     */
    private function _getToken()
    {
        if (file_exists($this->getTokenFile())) {
            return file_get_contents($this->getTokenFile());
        }

        return $this->_token;
    }

    /**
     * Установка токена, полученного от seranking.ru
     *
     * @param string $token
     *
     * @return $this
     */
    private function _setToken($token)
    {
        $this->_token = strval($token);

        return $this;
    }

    /**
     * Возвращает преконфигурированный базовыми настройками объект Request,
     * который затем можно использовать в дальнейшем для выполнения запросов к API.
     *
     * @return \yii\httpclient\Request
     */
    protected function initHttpClientRequest()
    {
        $client = new Client([
            'transport' => 'yii\httpclient\CurlTransport'
        ]);
        $request = $client->createRequest()
                          ->setUrl($this->getApiUrl())
                          ->setMethod('get')
                          ->setOptions([
                              CURLOPT_CONNECTTIMEOUT => 15,
                              CURLOPT_TIMEOUT => 10,
                              CURLOPT_FOLLOWLOCATION => true,
                              CURLOPT_MAXREDIRS => 3,
                          ]);

        return $request;
    }

    /**
     * Залогинивание в системе seranking.ru для получение токена.
     * Если логин и/или пароль переданы в качестве параметров, при авторизации
     * будут использоваться именно они.
     * В противном случае метод попробует использовать логин и пароль,
     * указанные при инициализации компонента.
     *
     * @param string|null $login логин (email)
     * @param string|null $password пароль
     *
     * @return $this
     * @throws Exception
     */
    public function login($login = null, $password = null)
    {
        $request = $this->initHttpClientRequest();
        $request->setData([
            'method' => 'login',
            'login' => null !== $login ? $login : $this->getLogin(),
            'pass' => null !== $password ? md5($password) : $this->getPasswordMd5Hash()
        ]);

        $response = $request->send();
        if ($response->getIsOk()) {
            $data = Json::decode($response->getContent());
            $this->_setToken($data['token']);

            return $this;
        } else {
            throw new Exception(
                'Cannot perform login request. Response code: ' . $response->getStatusCode(),
                $response->getStatusCode()
            );
        }
    }

    /**
     * Возвращает список (массив) сайтов пользователя.
     *
     * Из документации:
     * Для этого метода нет параметров. Возвращает список всех сайтов клиента.
     * Описание параметров, возвращаемых для каждого сайта:
     * - id - уникальный идентификатор сайта (ID)
     * - name - урл сайта
     * - title - название сайта
     * - todayAvgPosition - средняя позиция за последнюю дату снятия позиций (сегодня)
     * - yesterdayAvgPosition - средняя позиция за предыдущую дату снятия позиций (вчера)
     * - totalUp - сколько позиций поднялось в выдаче
     * - totalDown - сколько позиций опустилось в выдаче
     * - keysCount - всего запросов в сайте
     * - process - текущий процент обработки позиций сайта
     * - SEs - массив поисковиков, к которым привязан сайт, каждый элемент - массив с тремя элементами -
     * - seID (ID поисковика) , regionID (ID региона яндекса, если поисковик - не яндекс, то null),
     * - regionName (название города (или индекс), если такой был указан для google)
     * - group_id - ID группы сайта
     *
     * @throws Exception
     */
    public function getSites()
    {
        $request = $this->initHttpClientRequest();
        $response = $request
            ->setData([
                'method' => 'sites',
                'token' => $this->_getToken()
            ])
            ->send();

        $data = Json::decode($response->getContent());
        if ($response->getIsOk()) {
            return $data;
        } else {
            throw new Exception(
                'Cannot perform request to sites() method!' .
                (isset($data['code']) ? ' Code: ' . $data['code'] . '.' : '') .
                (isset($data['message']) ? ' Message: ' . $data['message'] . '.' : ''),
                $response->getStatusCode()
            );
        }
    }

    /**
     * Возвращает список (массив) поисковых систем.
     * Для этого метода нет параметров. Возвращает список в массиве всех поисковиков
     * с возможными регионами (для яндекса).
     *
     * Данные в каждом элементе массива:
     * - id - уникальный идентификатор поисковой системы
     * - name - название
     * - regionid - ID региона для searchVolume
     * - regions - массив регионов (для яндекса)
     *
     * @throws Exception
     */
    public function getSearchEngines()
    {
        $enginesFile = $this->getRuntimeDir() . DIRECTORY_SEPARATOR . 'engines';
        if (file_exists($enginesFile) && time() - filectime($enginesFile) <= $this->refreshTime) {
            return unserialize(file_get_contents($enginesFile));
        }

        $request = $this->initHttpClientRequest();
        $response = $request
            ->setData([
                'method' => 'searchEngines',
                'token' => $this->_getToken()
            ])
            ->send();

        $data = Json::decode($response->getContent());
        if ($response->getIsOk()) {
            $engines = ArrayHelper::index($data, 'id');
            file_put_contents($enginesFile, serialize($engines));

            return $engines;
        } else {
            throw new Exception(
                'Cannot perform request to searchEngines() method!' .
                (isset($data['code']) ? ' Code: ' . $data['code'] . '.' : '') .
                (isset($data['message']) ? ' Message: ' . $data['message'] . '.' : ''),
                $response->getStatusCode()
            );
        }
    }

    /**
     * Возвращает список запросов, связанных с сайтом.
     * При успешном вызове возвращает результат вида:
     * <code>
     * [
     *      {"id":1,"name":"ключ1","group_id":"11", "link":null, "first_check_date":null},
     *      {"id":2,"name":"ключ2","group_id":"22", "link":"http://mysite.ru/", "first_check_date":"2014-02-03"},
     *      ....
     * ]
     * </code>
     * @param integer $siteid уникальный идентификатор сайта (обязательный параметр)
     *
     * @return mixed
     * @throws Exception
     */
    public function getSiteKeywords($siteid)
    {
        $request = $this->initHttpClientRequest();
        $response = $request
            ->setData([
                'method' => 'siteKeywords',
                'siteid' => intval($siteid),
                'token' => $this->_getToken()
            ])
            ->send();

        $data = Json::decode($response->getContent());
        if ($response->getIsOk()) {
            return $data;
        } else {
            throw new Exception(
                'Cannot perform request to siteKeywords() method!' .
                (isset($data['code']) ? ' Code: ' . $data['code'] . '.' : '') .
                (isset($data['message']) ? ' Message: ' . $data['message'] . '.' : ''),
                $response->getStatusCode()
            );
        }
    }

    /**
     * Получение статистики по запросам.
     *
     * Из документации:
     * При успешном вызове возвращает результат вида:
     * <code>
     * [
     *      {"seID":"1","regionID":null,"keywords":[{"id":"1","positions":[{"date":"2013-09-03",
     *      "change":"1","pos":"1"},...]]},
     *      ....
     * ]
     * </code>
     * Возвращает массив из всех поисковиков сайта. В каждом поисковике - массив keywords,
     * состоящий из элементов вида {"id":123,"positions":[...],"landing_pages":[...]} .
     * Пример одного элемента массива keywords:
     * <code>
     * {
     *      "id": "4188",
     *      "positions": [
     *          {"date": "2014-06-20", "pos": "2", "change": 0},
     *          {"date": "2014-06-21", "pos": "2", "change": 0},
     *          {"date": "2014-06-22", "pos": "3", "change": 0},
     *          {"date": "2014-06-23", "pos": "4", "change": -1}
     *      ],
     *      "landing_pages": [
     *          {"url": "http:\/\/mysite.com\/", "date": "2014-02-06"},
     *          {"url": "http:\/\/mysite.com\/page1", "date": "2014-02-08"}
     *      ]
     * }
     * </code>
     *
     * Описание возвращаемых параметров:
     * - id - уникальный идентификатор запроса
     * - positions - массив с элементами:
     * -- date - дата в формате yyyy-mm-dd
     * -- change - изменение позиции по сравнению с пред. датой (может быть отрицательное)
     * -- pos - текущая позиция
     * - landing_pages - массив с элементами:
     * -- date - дата в формате yyyy-mm-dd
     * -- url - урл в выдаче
     *
     * @param integer $siteid уникальный идентификатор сайта (обязательный параметр)
     * @param string $dateStart дата начала в формате yyyy-mm-dd (необязательный параметр, по-умолчанию - сегодня
     *                           минус неделя)
     * @param string $dateEnd дата конца в формате yyyy-mm-dd (необязательный параметр, по-умолчанию - сегодня)
     * @param array $SE айдишники поисковиков, на которые надо отобразить статистику - массив ID поисковиков
     *                           сайта (для яндекса указывается в формате IDпоисковика~IDрегиона) Если не указан -
     *                           отображается для всех поисковиков сайта (необязательный параметр)
     *
     * @return mixed
     * @throws Exception
     */
    public function getStat($siteid, $dateStart = null, $dateEnd = null, array $SE = [])
    {
        $arr = [
            'method' => 'stat',
            'siteid' => intval($siteid),
            'dateStart' => null !== $dateStart ? $dateStart : date('Y-m-d', time() - 3600 * 24 * 7),
            'endDate' => null !== $dateEnd ? $dateEnd : date('Y-m-d'),
            'token' => $this->_getToken()
        ];
        if (!empty($SE)) {
            $arr = ArrayHelper::merge($arr, $SE);
        }

        $request = $this->initHttpClientRequest();
        $request->setData($arr);
        $response = $request->send();

        $data = Json::decode($response->getContent());
        if ($response->getIsOk()) {
            return $data;
        } else {
            throw new Exception(
                'Cannot perform request to stat() method!' .
                (isset($data['code']) ? ' Code: ' . $data['code'] . '.' : '') .
                (isset($data['message']) ? ' Message: ' . $data['message'] . '.' : ''),
                $response->getStatusCode()
            );
        }
    }

    /**
     * Прекращение сеанса.
     *
     * Из документации:
     * Для этого метода нет параметров. Сбрасывает access-token, полученный при авторизации.
     * После вызова метода token, полученный ранее, становится недействительным.
     *
     * @return $this
     * @throws Exception
     */
    public function logout()
    {
        $request = $this->initHttpClientRequest();
        $response = $request
            ->setData([
                'method' => 'logout',
                'token' => $this->_getToken()
            ])
            ->send();

        if ($response->getIsOk()) {
            return $this;
        } else {
            throw new Exception(
                'Cannot perform request to logout method! Response code: ' . $response->getStatusCode(),
                $response->getStatusCode()
            );
        }
    }

    /**
     * Добавление сайта.
     *
     * Из документации:
     * Возвращает ключ siteid (ID добавленого сайта) в массиве результата при успешном вызове.
     * Параметры, передаваемые в json-encoded элементе 'data' в POST-запросе:
     * - url - урл сайта (обязательный параметр)
     * - title - название сайта (обязательный параметр)
     * - depth - глубина сбора позиций (50,100,150,200), по-умолчанию - 100
     * - subdomain_match - учитывать сабдомены в выдаче? (0 или 1), по-умолчанию - 0
     * - exact_url - точный URL? (0 или 1), по-умолчанию - 0
     * - manual_check_freq - частота сбора позиций -
     * ('check_daily','check_1in3','check_weekly','check_yandex_up','manual'), по-умолчанию - check_daily
     * - auto_reports - еженедельный отчет? (0 или 1), по-умолчанию - 1
     * - group_id - ID группы, куда добавить созданный сайт
     * - day_of_week - если указан manual_check_freq=check_weekly, то в этом параметре можно задать день недели.
     * Значения от 1 (понедельник) до 7 (воскресенье).
     *
     * @param array $config - конфигурационный массив с параметрами, которые описаны выше
     *
     * @return integer id добавленного сайта
     * @throws Exception
     */
    public function addSite(array $config)
    {
        $validParams = [
            'url',
            'title',
            'depth',
            'subdomain_match',
            'exact_url',
            'manual_check_freq',
            'auto_reports',
            'group_id',
            'day_of_week'
        ];
        foreach ($config as $k => $v) {
            if (!in_array($k, $validParams)) {
                unset($config[$k]);
            }
        }

        $request = $this->initHttpClientRequest();
        $response = $request
            ->setUrl($this->getApiUrl() . '?' . 'method=addSite&token=' . $this->_getToken())
            ->setMethod('post')
            ->setData([
                'data' => Json::encode($config)
            ])
            ->send();

        $data = Json::decode($response->getContent());
        if ($response->getIsOk()) {
            return $data['siteid'];
        } else {
            throw new Exception(
                'Cannot perform request to addSite() method!' .
                (isset($data['code']) ? ' Code: ' . $data['code'] . '.' : '') .
                (isset($data['message']) ? ' Message: ' . $data['message'] . '.' : ''),
                $response->getStatusCode()
            );
        }
    }

    /**
     * Удаление сайта по заданному id. В случае успеха возвращает true.
     *
     * Из документации:
     * Возвращает ключ status (=1) в массиве результата при успешном вызове. параметры,
     * передаваемые в json-encoded элементе 'data' в POST-запросе:
     * siteid - ID сайта для удаления (обязательный параметр).
     *
     * @param integer $siteid id удаляемого сайта
     *
     * @return boolean
     * @throws Exception
     */
    public function deleteSite($siteid)
    {
        $config = [
            'siteid' => intval($siteid)
        ];

        $request = $this->initHttpClientRequest();
        $response =
            $request
                ->setUrl($this->getApiUrl() . '?method=deleteSite&token=' . $this->_getToken())
                ->setMethod('post')
                ->setData([
                    'data' => Json::encode($config)
                ])
                ->send();

        $data = Json::decode($response->getContent());
        if ($response->getIsOk()) {
            return $data['status'] == 1;
        } else {
            throw new Exception(
                'Cannot perform request to deleteSite() method!' .
                (isset($data['code']) ? ' Code: ' . $data['code'] . '.' : '') .
                (isset($data['message']) ? ' Message: ' . $data['message'] . '.' : ''),
                $response->getStatusCode()
            );
        }
    }

    /**
     * Список регионов для avg.search volume.
     *
     * Из документации:
     * Для этого метода нет параметров. Возвращает список всех регионов для получения avg.search volume.
     * При успешном вызове возвращает результат вида:
     * <code>
     * [
     *      {"id":"1","name":"Afghanistan"},
     *      {"id":"2","name":"Algeria"},
     *      ...
     * ]
     * </code>
     *
     * @return mixed
     * @throws Exception
     */
    public function getSearchVolumeRegions()
    {
        $request = $this->initHttpClientRequest();
        $response = $request
            ->setData([
                'method' => 'searchVolumeRegions',
                'token' => $this->_getToken()
            ])
            ->send();

        $data = Json::decode($response->getContent());
        if ($response->getIsOk()) {
            return $data;
        } else {
            throw new Exception(
                'Cannot perform request to searchVolumeRegions() method!' .
                (isset($data['code']) ? ' Code: ' . $data['code'] . '.' : '') .
                (isset($data['message']) ? ' Message: ' . $data['message'] . '.' : ''),
                $response->getStatusCode()
            );
        }
    }

    /**
     * Получение avg.search volume для одного запроса.
     *
     * Из документации:
     * При успешном вызове возвращает результат вида:
     * <code>
     * {"volume":123500}
     * </code>
     *
     * @param integer $regionid ID региона. Все регионы и их ID можно получить в методе searchVolumeRegions
     *                          (обязательный параметр)
     * @param string $keyword ключевое слово (запрос), для которого будет получен avg.search volume. Должен быть
     *                          url-encoded в урле, т.е. "ключ" превратится в %D0%BA%D0%BB%D1%8E%D1%87 (обязательный
     *                          параметр)
     *
     * @return mixed
     * @throws Exception
     */
    public function getKeySearchVolume($regionid, $keyword)
    {
        $request = $this->initHttpClientRequest();
        $response = $request
            ->setData([
                'method' => 'keySearchVolume',
                'regionid' => $regionid,
                'keyword' => $keyword,
                'token' => $this->_getToken()
            ])
            ->send();

        $data = Json::decode($response->getContent());
        if ($response->getIsOk()) {
            return $data;
        } else {
            throw new Exception(
                'Cannot perform request to keySearchVolume() method!' .
                (isset($data['code']) ? ' Code: ' . $data['code'] . '.' : '') .
                (isset($data['message']) ? ' Message: ' . $data['message'] . '.' : ''),
                $response->getStatusCode()
            );
        }
    }

    /**
     * Получение avg.search volume для списка запросов.
     * todo: пока без реализации...
     */
    public function getKeySearchVolumeList($regionid, array $keyword)
    {
        throw new NotSupportedException("Method is not supported yet.");
    }

    /**
     * Добавление запросов к сайту.
     *
     * Из документации:
     * Возвращает массив из двух элементов: 'added' - количество реально добавленных запросов,
     * 'ids' - массив ID добавленных запросов. параметры, передаваемые в json-encoded элементе 'data' в POST-запросе:
     * - siteid - уникальный идентификатор сайта (обязательный параметр)
     * - keywords - массив запросов (обязательный параметр)
     * - groupid - ID группы запросов (если не указать, будет использована группа по-умолчанию)
     * При успешном вызове возвращает результат вида:
     * {
     *      "added": "2",
     *      "ids": [111,112]
     * }
     *
     * @param integer $siteid уникальный идентификатор сайта (обязательный параметр)
     * @param array $keywords массив запросов (обязательный параметр)
     * @param integer $groupid ID группы запросов (если не указать, будет использована группа по-умолчанию)
     *
     * @return mixed
     * @throws Exception
     */
    public function addSiteKeywords($siteid, array $keywords, $groupid = null)
    {
        $config = [
            'siteid' => intval($siteid),
            'keywords' => $keywords,
            //'groupid' => null !== $groupid ? $groupid : '' // todo: как быть здесь?
        ];

        $request = $this->initHttpClientRequest();
        $response = $request
            ->setUrl($this->getApiUrl() . '?method=addSiteKeywords&token=' . $this->_getToken())
            ->setMethod('post')
            ->setData([
                'data' => Json::encode($config)
            ])
            ->send();

        $data = Json::decode($response->getContent());
        if ($response->getIsOk()) {
            return $data;
        } else {
            throw new Exception(
                'Cannot perform request to addSiteKeywords() method!' .
                (isset($data['code']) ? ' Code: ' . $data['code'] . '.' : '') .
                (isset($data['message']) ? ' Message: ' . $data['message'] . '.' : ''),
                $response->getStatusCode()
            );
        }
    }

    /**
     * Расширенное добавление запросов к сайту.
     *
     * Из документации:
     * Возвращает массив из двух элементов: 'added' - количество реально добавленных запросов,
     * 'ids' - массив ID добавленных запросов.
     * Параметры, передаваемые в json-encoded элементе 'data' в POST-запросе:
     * - siteid - уникальный идентификатор сайта (обязательный параметр)
     * - keywords - ассоциативный массив запросов, пары запрос=>целевая_ссылка (обязательный параметр)
     * - groupid - ID группы запросов (если не указать, будет использована группа по-умолчанию)
     * - is_strict_target_urls - Проверять позиции только для указанных целевых ссылок (0 или 1, по-умолчанию - 0)
     * При успешном вызове возвращает результат вида:
     * {
     *      "added": "2",
     *      "ids": [111,112]
     * }
     *
     * @param integer $siteid
     * @param array $keywords
     * @param integer $groupid id
     * @param bool $is_strict_target_urls
     *
     * @return mixed
     * @throws Exception
     */
    public function addSiteKeywordsExt($siteid, array $keywords, $groupid = null, $is_strict_target_urls = true)
    {
        $is_strict_target_urls = intval($is_strict_target_urls);
        $siteid = intval($siteid);
        if (!is_array($keywords) || empty($keywords)) {
            throw new InvalidParamException(
                'Не задан массив с ключевыми словами либо он пуст.'
            );
        }
        $processedKeywords = [];
        foreach ($keywords as $kw => $url) {
            $kw = mb_strtolower($kw, 'UTF-8');
            // замена всех запрещенных символов кроме a-z, 0-9, а-я, пробелов, дефисов и точки
            $pattern = '/[^a-z\dа-яё.\s-]+/iu';
            $kw = preg_replace($pattern, '', $kw);
            // замена пробельных символов, если они встречаются 2 и более раз
            $pattern = '/[\s]{2,}/u';
            $kw = preg_replace($pattern, ' ', $kw);
            $processedKeywords[$kw] = $url;
        }

        $config = [
            'siteid' => intval($siteid),
            'keywords' => $processedKeywords,
            'is_strict_target_urls' => $is_strict_target_urls
        ];

        $request = $this->initHttpClientRequest();
        $response = $request
            ->setUrl($this->getApiUrl() . '?method=addSiteKeywordsExt&token=' . $this->_getToken())
            ->setMethod('post')
            ->setData([
                'data' => Json::encode($config)
            ])
            ->send();

        $data = Json::decode($response->getContent());
        if ($response->getIsOk()) {
            return $data;
        } else {
            throw new Exception(
                'Cannot perform request to addSiteKeywordsExt() method!' .
                (isset($data['code']) ? ' Code: ' . $data['code'] . '.' : '') .
                (isset($data['message']) ? ' Message: ' . $data['message'] . '.' : ''),
                $response->getStatusCode()
            );
        }
    }

    /**
     * Удаление запросов для заданного сайта по их идентификаторам.
     * Возвращает булево значение (true) в случае успешного удаления.
     *
     * @param integer $siteid идентификатор сайта
     * @param array $keywords_ids идентификаторы запросов
     *
     * @return bool
     * @throws Exception
     */
    public function deleteKeywords($siteid, array $keywords_ids)
    {
        $config = [
            'siteid' => intval($siteid),
            'keywords_ids' => $keywords_ids
        ];

        $request = $this->initHttpClientRequest();
        $response = $request
            ->setUrl($this->getApiUrl() . '?method=deleteKeywords&token=' . $this->_getToken())
            ->setMethod('post')
            ->setData([
                'data' => Json::encode($config)
            ])
            ->send();

        $data = Json::decode($response->getContent());
        if ($response->getIsOk()) {
            return $data['status'] == 1;
        } else {
            throw new Exception(
                'Cannot perform request to deleteKeywords() method!' .
                (isset($data['code']) ? ' Code: ' . $data['code'] . '.' : '') .
                (isset($data['message']) ? ' Message: ' . $data['message'] . '.' : ''),
                $response->getStatusCode()
            );
        }
    }

    /**
     * Обновление/добавление поисковиков сайта.
     * В случае успеха возвращает true.
     *
     * Из документации:
     *  Возвращает ключ status (=1) в массиве результата при успешном вызове. параметры,
     * передаваемые в json-encoded элементе 'data' в POST-запросе:
     * - siteid - ID сайта(обязательный параметр)
     * - se - массив поисковых систем в виде (IDпоисковика1=>названиеРегиона1,IDпоисковика2=>названиеРегиона2...) .
     * "названиеРегиона" задаётся только для гугл-поисковиков (для остальных пустая строка или null).
     * для яндекс поисковиков ID поисковика задаётся как IDпоисковойСистемы~IDрегиона (например,
     * яндекс-москва это 411~213) (обязательный параметр).
     *
     * Для удаления всех поисковиков с их регионами для сайта, можно передать такой массив:
     * $se = [
     *      '0'
     * ];
     *
     * @param integer $siteid
     * @param array $se
     *
     * @return bool
     * @throws Exception
     */
    public function updateSiteSE($siteid, array $se)
    {
        $config = [
            'siteid' => intval($siteid),
            'se' => $se
        ];

        $request = $this->initHttpClientRequest();
        $response = $request
            ->setUrl($this->getApiUrl() . '?method=updateSiteSE&token=' . $this->_getToken())
            ->setMethod('post')
            ->setData([
                'data' => Json::encode($config)
            ])
            ->send();

        $data = Json::decode($response->getContent());
        if ($response->getIsOk()) {
            return $data['status'] == 1;
        } else {
            throw new Exception(
                'Cannot perform request to updateSiteSE() method!' .
                (isset($data['code']) ? ' Code: ' . $data['code'] . '.' : '') .
                (isset($data['message']) ? ' Message: ' . $data['message'] . '.' : ''),
                $response->getStatusCode()
            );
        }
    }

    /**
     * Получение файла с регионами Yandex и его сохранение в сериализованной форме.
     *
     * @return bool
     * @throws Exception
     */
    public function getYandexRegionsFile()
    {
        $url = 'https://yandex.ru/yaca/geo.c2n';
        $yaRegionsFile = $this->getRuntimeDir() . DIRECTORY_SEPARATOR . 'ya.geo';

        $request = $this->initHttpClientRequest();
        // override options
        $options = ArrayHelper::merge(
            $request->getOptions(),
            [
                CURLOPT_SSL_VERIFYPEER => false
            ]
        );

        $response = $request->setUrl($url)->setOptions($options)->send();
        if ($response->getIsOk()) {
            $data = $response->getContent();
            $data = iconv('windows-1251', 'UTF-8', $data);
            if (false === file_put_contents($yaRegionsFile, $data)) {
                throw new Exception(
                    "Cannot write to file: {$yaRegionsFile}"
                );
            }

            return true;
        } else {
            throw new Exception(
                'Cannot get Yandex regions from source. Response code is ' . $response->getStatusCode(),
                $response->getStatusCode()
            );
        }
    }
}