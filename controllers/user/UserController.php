<?php
/**
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2018 HumHub GmbH & Co. KG
 * @license https://www.humhub.com/licences
 */

namespace humhub\modules\rest\controllers\user;

use humhub\modules\rest\components\BaseController;
use humhub\modules\rest\definitions\UserDefinitions;
use humhub\modules\space\models\Membership;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\Password;
use humhub\modules\user\models\Profile;
use humhub\modules\user\models\Group;
use humhub\modules\user\models\User;
use Yii;
use yii\web\HttpException;


/**
 * Class AccountController
 */
class UserController extends BaseController
{

    public function actionIndex()
    {
        $results = [];
        $query = User::find();

        $pagination = $this->handlePagination($query);
        foreach ($query->all() as $user) {
            $results[] = UserDefinitions::getUser($user);
        }
        return $this->returnPagination($query, $pagination, $results);
    }


    public function actionView($id)
    {
        $user = User::findOne(['id' => $id]);
        if ($user === null) {
            return $this->returnError(404, 'User not found!');
        }

        return UserDefinitions::getUser($user);
    }

    public function actionUpdate($id)
    {
        $user = User::findOne(['id' => $id]);
        if ($user === null) {
            return $this->returnError(404, 'User not found!');
        }

        $user->scenario = 'editAdmin';
        $userData = Yii::$app->request->getBodyParam("account", []);
        if (!empty($userData)) {
            $user->load($userData, '');
            $user->validate();
        }

        $profile = null;
        $profileData = Yii::$app->request->getBodyParam("profile", []);

        if (!empty($profileData)) {
            $profile = $user->profile;
            $profile->scenario = 'editAdmin';
            $profile->load($profileData, '');
            $profile->validate();
        }

        $password = null;
        $passwordData = Yii::$app->request->getBodyParam("password", []);
        if (!empty($passwordData)) {
            $password = new Password();
            $password->scenario = 'registration';
            $password->load($passwordData, '');
            $password->newPasswordConfirm = $password->newPassword;
            $password->validate();
        }

        if ((!empty($userData) && $user->hasErrors()) ||
            ($password !== null && $password->hasErrors()) ||
            ($profile !== null && $profile->hasErrors())
        ) {
            return $this->returnError(400, 'Validation failed', [
                'profile' => ($profile !== null) ? $profile->getErrors() : null,
                'account' => $user->getErrors(),
                'password' => ($password !== null) ? $password->getErrors() : null,
            ]);
        }

        if (!$user->save()) {
            return $this->returnError(500, 'Internal error while save user!');
        }

        if ($profile !== null && !$profile->save()) {
            return $this->returnError(500, 'Internal error while save profile!');

        }

        if ($password !== null) {
            $password->user_id = $user->id;
            $password->setPassword($password->newPassword);
            if (!$password->save()) {
                return $this->returnError(500, 'Internal error while save new password!');
            }
        }

        return $this->actionView($user->id);
    }

    private function isValidCourseIds($course_ids){
        foreach ($course_ids as $key => $course_id) {
            $course = Space::findOne(['id'=>$course_id]);

            if ($course == null) {
                return $course_id;
            }
        }
        return false;
    }

    private function addToCourses($user_id, $course_ids){
        foreach ($course_ids as $key => $course_id) {
            $course = Space::findOne(['id'=>$course_id]);
            if (!$course->isMember($user_id)){
                $course->addMember($user_id);
            }
        }
    }

    /**
     *
     * @return array
     * @throws HttpException
     */
    public function actionCreate()
    {
        $group_id = Yii::$app->request->getBodyParam("group_id", '121');
        $group = Group::findOne(['id' => $group_id]);
        if ($group === null) {
            return $this->returnError(404, 'Group "'.$group_id.'" not found!');
        }

        $course_ids = Yii::$app->request->getBodyParam("course_ids", '');
        $course_ids = explode(",", trim($course_ids));
        if ($course_ids) {
            $invalid_course_id = $this->isValidCourseIds($course_ids);
            if ($invalid_course_id){
                return $this->returnError(404, 'Course "'.$invalid_course_id.'" not found!');
            }
        }

        $user = new User();
        $user->scenario = 'editAdmin';
        $user->load(Yii::$app->request->getBodyParam("account", []), '');
        $user->group_id = $group_id;
        $user->status = User::STATUS_ENABLED;
        $user->validate();

        $profile = new Profile();
        $profile->scenario = 'editAdmin';
        $profile->load(Yii::$app->request->getBodyParam("profile", []), '');
        $profile->validate();

        $password = new Password();
        $password->scenario = 'registration';
        $password->load(Yii::$app->request->getBodyParam("password", []), '');
        $password->newPasswordConfirm = $password->newPassword;
        $password->validate();

        if ($user->hasErrors() || $password->hasErrors() || $profile->hasErrors()) {
            return $this->returnError(400, 'Validation failed', [
                'password' => $password->getErrors(),
                'profile' => $profile->getErrors(),
                'account' => $user->getErrors(),
            ]);
        }

        if ($user->save()) {
            $profile->user_id = $user->id;
            $password->user_id = $user->id;
            $password->setPassword($password->newPassword);
            if ($profile->save() && $password->save()) {
                $this->addToCourses($user->id, $course_ids);
                $this->sendEmailNotification($user, $password->newPassword);
                return $this->actionView($user->id);
            }
        }

        Yii::error('Could not create validated user.', 'api');
        return $this->returnError(500, 'Internal error while save user!');
    }

    private function sendEmailNotification($userModel, $userPassword){
        $profileModel = $userModel->profile;
        $contenido = '<div style="font-family: Arial, Helvetica, sans-serif; margin-left: 30px;">
                        <p style="margin-bottom: 10px">
                            Estimad@ '.$profileModel->firstname.':<br>
                        </p>
                        <p style="margin-bottom: 10px">
                            Le damos la bienvenida al Campus Virtual de ICB, donde podr&aacute; acceder al material de estudio, videos de las clases y realizar consultas a los profesores.<br>
                            Sus credenciales para accesder son las siguientes:
                            <ul>
                                <li>Nombre de usuario: '.$userModel->username.'</li>
                                <li>Contrase&ntilde;a: '.$userPassword.'</li>
                            </ul>
                            Para acceder al sitio, haga click <a href="http://www.icbargentina.com/campusvirtual"><b>aqu&iacute;</b></a>.<br>
                        </p>
                        <p style="margin-bottom: 10px">
                            Saludos,<br>
                        </p>
                        <p style="margin-bottom: 10px">
                            ICB ARGENTINA<br>
                            <font style="color: #909090;font-family: serif;">
                                Departamento Acad&eacute;mico<br>
                                Casa Central: Av. Alem 668, piso 6&deg;A - CABA<br>
                                Tel: (011) 5218-4608<br>
                                <a href="www.icbargentina.com">www.icbargentina.com</a><br>
                            </font>
                        </p>
                    </div>';
        $mail = Yii::$app->mailer->compose(['html' => '@humhub/views/mail/TextOnly'], ['message' => $contenido]);
        $mail->setFrom([\humhub\models\Setting::Get('systemEmailAddress', 'mailing') => \humhub\models\Setting::Get('systemEmailName', 'mailing')]);
        $mail->setTo($userModel->email);
        $mail->setSubject('Bienvenido al Campus Virtual de ICB');
        $mail->send();
    }

    public function actionDelete($id)
    {
        $user = User::findOne(['id' => $id]);
        if ($user === null) {
            return $this->returnError(404, 'User not found!');
        }

        if ($this->softDelete($user)) {
            return $this->returnSuccess('User successfully soft deleted!');
        }

        return $this->returnError(500, 'Internal error while soft delete user!');
    }

    private function softDelete($user) {
        if ($user->status != User::STATUS_DISABLED){
            // Check if the user owns some spaces
            foreach (Membership::GetUserSpaces($user->id) as $space) {
                if ($space->isSpaceOwner($user->id)) {
                    throw new Exception("No se puede borrar el usuario '".$user->username."' al ser owner del curso '".$space->name."'");
                }
            }

            // disable user
            $user->status = User::STATUS_DISABLED;
            $user->save();

            // Cancel all space memberships
            foreach (Membership::findAll(array('user_id' => $user->id)) as $membership) {
                $membership->space->removeMember($user->id);
            }

            // Cancel all space invites by the user
            foreach (Membership::findAll(array('originator_user_id' => $user->id, 'status' => Membership::STATUS_INVITED)) as $membership) {
                $membership->space->removeMember($membership->user_id);
            }

            return true;
        }
        return false;
    }

    public function actionHardDelete($id)
    {
        $user = User::findOne(['id' => $id]);
        if ($user === null) {
            return $this->returnError(404, 'User not found!');
        }

        if ($user->delete()) {
            return $this->returnSuccess('User successfully deleted!');
        }

        return $this->returnError(500, 'Internal error while soft delete user!');
    }


}