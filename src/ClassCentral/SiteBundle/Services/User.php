<?php

namespace ClassCentral\SiteBundle\Services;

use ClassCentral\SiteBundle\Entity\Course;
use ClassCentral\SiteBundle\Entity\MoocTrackerSearchTerm;
use ClassCentral\SiteBundle\Entity\UserCourse;
use ClassCentral\SiteBundle\Entity\UserPreference;
use Doctrine\ORM\Query\ResultSetMapping;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class User {

    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function login(\ClassCentral\SiteBundle\Entity\User $user)
    {
        $token = new UsernamePasswordToken($user, null,'secured_area',$user->getRoles());
        $this->container->get('security.context')->setToken($token);
    }

    /**
     * Creates a new user
     * @param \ClassCentral\SiteBundle\Entity\User $user
     * @return null
     */
    public function createUser(\ClassCentral\SiteBundle\Entity\User $user, $verificationEmail = true)
    {
        $userSession = $this->container->get('user_session');
        $logger = $this->container->get('logger');
        $em = $this->container->get('doctrine')->getManager();
        $router = $this->container->get('router');
        $session = $this->container->get('session');
        $newsletter = $em->getRepository('ClassCentralSiteBundle:Newsletter')->findOneByCode("mooc-report");

        $user = $this->signup($user, $verificationEmail); // true - verification email

        // Normal flow. Subscribe the user to a mooc report newsletter
        if($newsletter)
        {
            // Save the user preferences
            $user->subscribe($newsletter);
            $em->persist($user);
            $em->flush();
        }

        // Check where the user reached the signed in page
        $referralDetails = $userSession->getSignupReferralDetails();
        $redirectUrl = null;
        if(!empty($referralDetails))
        {
            if(array_key_exists('mooc',$referralDetails))
            {
                $this->saveCourseInMoocTracker($user,$referralDetails['mooc']);
            }
            else if (array_key_exists('searchTerm',$referralDetails))
            {
                $this->saveSearchTermInMoocTracker($user,$referralDetails['searchTerm']);
            }
            else if (array_key_exists('listId',$referralDetails))
            {
                // Add the course to the users library
                $course = $em->find('ClassCentralSiteBundle:Course',$referralDetails['courseId']);
                if($course)
                {
                    $this->addCourse($user,$course, $referralDetails['listId']);
                    $name = $course->getName();
                    // Send a notification message
                    $userSession->notifyUser(
                        UserSession::FLASH_TYPE_SUCCESS,
                        'Course added',
                        "<i>{$name}</i> added to <a href='/user/courses'>My Courses</a> successfully"
                    );
                }
                else
                {
                    $logger->error("Course with id {$referralDetails['courseId']} not found");
                }
            }
            else if (array_key_exists('review', $referralDetails))
            {
                // Redirect to the create review page
                $course = $em->find('ClassCentralSiteBundle:Course',$referralDetails['courseId']);
                if($course)
                {
                    // Redirect to create review page
                    $redirectUrl = $router->generate('review_new', array('courseId' =>$referralDetails['courseId']));
                }
                else
                {
                    $logger->error("Create Review flow: Course with id {$referralDetails['courseId']} not found");
                }
            }


            $userSession->clearSignupReferralDetails();
            $userSession->saveUserInformationInSession(); // Update the session

            if($redirectUrl)
            {
                return ($redirectUrl);
            }
        }

        // Check if it was the review first signup later flow
        $review = $this->createReviewFromSession($user);
        if($review instanceof \ClassCentral\SiteBundle\Entity\Review)
        {
            // Review created successfully. Redirect to the router page
            return $router->generate('ClassCentralSiteBundle_mooc', array('id'=> $review->getCourse()->getId(),'slug' => $review->getCourse()->getSlug() ));
        }


        return $router->generate('user_library');
    }

    /**
     * Creates review if its stored in a session.
     * Part of review first signup later flow
     * @param $user
     * @param $session
     * @return bool
     */
    public function createReviewFromSession($user)
    {
        $session = $this->container->get('session');
        $userReview = $session->get('user_review');
        $ru = $this->container->get('review');

        if(!empty($userReview))
        {
            // Save the review
            $courseId = $userReview['courseId'];
            $review = $ru->saveReview($courseId,$user,$userReview);
            $session->remove('user_review');
            return $review;
        }

        return false;
    }



    public function signup(\ClassCentral\SiteBundle\Entity\User $user, $emailVerification = true)
    {
        $em = $this->container->get('doctrine')->getManager();
        $templating = $this->container->get('templating');
        $mailgun = $this->container->get('mailgun');
        $verifyTokenService = $this->container->get('verification_token');
        $userSession = $this->container->get('user_session');

        $user->setEmail(strtolower($user->getEmail())); // Normalize the email
        $password = $user->getPassword();
        $user->setPassword($user->getHashedPassword($password));

        // If the email has subscriptions to different newsletters, transfer it over to this user
        $emailEntity = $em->getRepository('ClassCentralSiteBundle:Email')->findOneByEmail($user->getEmail());
        if($emailEntity)
        {
            foreach($emailEntity->getNewsletters() as $newsletter)
            {
                $user->addNewsletter($newsletter);
            }
        }

        $em->persist($user);
        $em->flush();

        // Create user prefrences for the user
        $this->initPreferences($user);

        // Login the user
        $this->login($user);

        // Create a successfull signup notification
        $userSession->notifyUser(
            UserSession::FLASH_TYPE_SUCCESS,
            'Account successfully created',
            "You can now build your own library of courses by adding them to <a href='/user/courses''>My Courses</a>",
            30 // 30 seconds delay
        );
        // Send a welcome email but not in the test environment
        if ($this->container->getParameter('kernel.environment') != 'test')
        {
            $html = $templating->renderResponse('ClassCentralSiteBundle:Mail:welcome.html.twig')->getContent();
            $mailgunResponse = $mailgun->sendIntroEmail($user->getEmail(),"'Dhawal Shah'<dhawal@class-central.com>","Welcome to Class Central",$html);

            if($emailVerification)
            {
               // Send an email for verification
                $value = array(
                    'verify' => 1,
                    'email' => $user->getEmail()
                );
                $tokenEntity = $verifyTokenService->create($value,\ClassCentral\SiteBundle\Entity\VerificationToken::EXPIRY_1_YEAR);
                $html = $templating->renderResponse('ClassCentralSiteBundle:Mail:confirm.email.html.twig',array('token' => $tokenEntity->getToken()))->getContent();
                $mailgunResponse = $mailgun->sendSimpleText($user->getEmail(),"no-reply@class-central.com","Please confirm your email",$html);

                // Send user a notification about this email
                $userSession->notifyUser(
                    UserSession::FLASH_TYPE_NOTICE,
                    'Confirm your email address',
                    "A confirmation email has been sent to <b>{$user->getEmail()}</b>. Click on the confirmation link in the email to activate your account",
                    60 // 1 minute delay
                );
            }
        }

        return $user;
    }

    /**
     * Adds a course to the users interested list.
     * A course can be added only once.
     * @param \ClassCentral\SiteBundle\Entity\User $user
     * @param Course $course
     * @param $listId
     */
    public function addCourse(\ClassCentral\SiteBundle\Entity\User $user, Course $course, $listId)
    {
        $em = $this->container->get('doctrine')->getManager();
        // Check if the list id is valid
        if(!array_key_exists($listId,UserCourse::$lists))
        {
            throw new \Exception("List id $listId is not valid");
        }

       // Remove the course if it exists
        $this->removeCourse($user, $course, $listId);

        //Save it if it does not exist
        $uc = new UserCourse();
        $uc->setCourse($course);
        $uc->setUser($user);
        $uc->setListId($listId);

        // Add course to user
        $user->addUserCourse($uc);
        $em->persist($uc);
        $em->flush();

        return $uc;
    }

    /**
     * Given a list id and a course removes it from the users listings
     * @param \ClassCentral\SiteBundle\Entity\User $user
     * @param Course $course
     * @param $listId
     */
    public function removeCourse(\ClassCentral\SiteBundle\Entity\User $user, Course $course, $listId)
    {
        $em = $this->container->get('doctrine')->getManager();
        $userCourseId = $this->getUserCourseId($user,$course,$listId);
        if($userCourseId)
        {
            $uc = $em->find('ClassCentralSiteBundle:UserCourse', $userCourseId);
            $em->remove($uc);
            $em->flush();

            return true;
        }

        // Course was not added before
        return false;

    }

    /**
     * Retrives the userCourse
     * There can be only one course added per user. So ignoring the list id
     * @param \ClassCentral\SiteBundle\Entity\User $user
     * @param Course $course
     * @param $listId
     */
    private function getUserCourseId(\ClassCentral\SiteBundle\Entity\User $user, Course $course, $listId)
    {
        $em = $this->container->get('doctrine')->getManager();
        $rsm = new ResultSetMapping();
        $rsm->addScalarResult('id', 'id');
        $query = $em->createNativeQuery("SELECT id FROM users_courses WHERE user_id = ? AND course_id = ?",$rsm);
        $query->setParameter('1', $user->getId());
        $query->setParameter('2', $course->getId());
        //$query->setParameter('3', $listId);
        $result = $query->getResult();

        if(empty($result))
        {
            return null;
        }
        else
        {
            return $result[0]["id"];
        }

    }

    /**
     * Updates or creates a iser preference if it does not exist
     * @param \ClassCentral\SiteBundle\Entity\User $user
     * @param $type
     * @param $value
     */
    public function updatePreference(\ClassCentral\SiteBundle\Entity\User $user, $type, $value)
    {
        $em = $this->container->get('doctrine')->getManager();

        if(!in_array($type, UserPreference::$validPrefs))
        {
            throw new \Exception("Preference $type is not a valid preference");
        }

        $prefMap = $user->getUserPreferencesByTypeMap();
        if(in_array($type,array_keys($prefMap)))
        {
            // Update the preferences
            $up = $prefMap[$type];
            $up->setValue($value);
            $em->persist($up);
        }
        else
        {
            // Create the preferences
            $up = new UserPreference();
            $up->setUser($user);
            $up->setType($type);
            $up->setValue($value);
            $em->persist($up);
        }

        $em->flush();

        return true;
    }

    /**
     * Initializes preferences for a particular user
     * @param \ClassCentral\SiteBundle\Entity\User $user
     * @param array $prefs
     */
    public function initPreferences(\ClassCentral\SiteBundle\Entity\User $user, $prefs = array())
    {
        $em = $this->container->get('doctrine')->getManager();

        // MOOC Tracker courses
        $upCourses = new UserPreference();
        $upCourses->setUser($user);
        $upCourses->setType(UserPreference::USER_PREFERENCE_MOOC_TRACKER_COURSES);
        $value = 1;
        if(in_array(UserPreference::USER_PREFERENCE_MOOC_TRACKER_COURSES, $prefs))
        {
            $value = $prefs[UserPreference::USER_PREFERENCE_MOOC_TRACKER_COURSES];
        }
        $upCourses->setValue($value);
        $em->persist($upCourses);

        // MOOC Tracker search terms
        $upSearchTerms = new UserPreference();
        $upSearchTerms->setUser($user);
        $upSearchTerms->setType(UserPreference::USER_PREFERENCE_MOOC_TRACKER_SEARCH_TERM);
        $value = 1;
        if(in_array(UserPreference::USER_PREFERENCE_MOOC_TRACKER_COURSES, $prefs))
        {
            $value = $prefs[UserPreference::USER_PREFERENCE_MOOC_TRACKER_COURSES];
        }
        $upSearchTerms->setValue($value);
        $em->persist($upSearchTerms);

        $em->flush();
    }

    public function saveSearchTermInMoocTracker($user,$searchTerm)
    {
        $userSession = $this->container->get('user_session');
        $em = $this->container->get('doctrine')->getManager();

        if(!$userSession->isSearchTermAddedToMT($searchTerm))
        {
            $mtSearchTerm = new MoocTrackerSearchTerm();
            $mtSearchTerm->setUser($user);
            $mtSearchTerm->setSearchTerm($searchTerm);
            $em->persist($mtSearchTerm);
            // Add the searchterm to user
            $user->addMoocTrackerSearchTerm($mtSearchTerm);
            $em->flush();

            $userSession->saveUserInformationInSession();
        }
    }


} 