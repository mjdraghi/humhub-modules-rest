<?php
/**
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2018 HumHub GmbH & Co. KG
 * @license https://www.humhub.com/licences
 */

namespace humhub\modules\rest\models;

use humhub\modules\rest\Module;
use humhub\models\Setting;
use Yii;
use yii\base\Model;

class ConfigureForm extends Model
{

    public $enabledForAllUsers;

    public $enabledUsers;

    public $jwtKey;

    public $jwtExpire;

    public $enableBasicAuth;

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['jwtKey'], 'string', 'min' => 32, 'max' => 128],
            [['enabledUsers'], 'safe'],
            [['enabledForAllUsers', 'enableBasicAuth'], 'boolean'],
            [['jwtExpire'], 'integer']
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'jwtKey' => Yii::t('RestModule.base', 'JWT Key'),
            'jwtExpire' => 'JWT Token Expiration',
            'enabledForAllUsers' => Yii::t('RestModule.base', 'Enabled for all registered users'),
            'enableBasicAuth' => 'Allow HTTP Basic Authentication'
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeHints()
    {
        return [
            'jwtKey' => 'If empty, a random key is generated automatically.',
            'jwtExpire' => 'in seconds. 0 for no JWT token expiration.',
            'enabledForAllUsers' => 'Please note, it is not recommended to enable the API for all users yet.',
        ];
    }

    public function loadSettings()
    {

        $this->jwtKey = Setting::Get('jwtKey', 'rest');
        if (empty($this->jwtKey)) {
            Setting::Set('jwtKey', Yii::$app->security->generateRandomString(86), 'rest');
            $this->jwtKey = Setting::Get('jwtKey', 'rest');  /** $settings->getSerialized('jwtKey'); **/
        }

        $this->enabledForAllUsers = (boolean)Setting::Get('enabledForAllUsers', 'rest');
        $this->enabledUsers = Setting::Get('enabledUsers', 'rest');
        $this->jwtExpire = (int)Setting::Get('jwtExpire', 'rest');
        $this->enableBasicAuth = (boolean)Setting::Get('enableBasicAuth', 'rest');

        return true;
    }

    public function saveSettings()
    {

        Setting::Set('jwtExpire', (int)$this->jwtExpire, 'rest');
        Setting::Set('jwtKey', $this->jwtKey, 'rest');
        Setting::Set('enabledForAllUsers', $this->enabledForAllUsers, 'rest');
        Setting::Set('enableBasicAuth', (boolean)$this->enableBasicAuth, 'rest');
        Setting::Set('enabledUsers', implode(",", (array)$this->enabledUsers), 'rest');

        return true;
    }

    public static function getInstance()
    {
        $config = new static;
        $config->loadSettings();

        return $config;
    }

}
