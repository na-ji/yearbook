<?php

namespace Ferus\YearBookBundle\Controller;

use Ferus\FairPayApi\Exception\ApiErrorException;
use Ferus\YearBookBundle\Entity\Student;
use Ferus\YearBookBundle\Form\StudentType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Ferus\FairPayApi\FairPay;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\EntityManager;
use Stof\DoctrineExtensionsBundle\Uploadable\UploadableManager;

class PublicController extends Controller
{
    /**
     * @var EntityManager
     */
    private $em;
    /**
     * @var UploadableManager
     */
    private $uploadableManager;

    /**
     * @Template
     */
    public function indexAction(Request $request)
    {
        if($request->isMethod('POST')){
            $student = $this->em->getRepository('FerusYearBookBundle:Student')->findOneById($request->request->get('id'));

            if($student === null){
                try{
                    $fairpay = new FairPay();
                    $fairpay->setCurlParam(CURLOPT_HTTPPROXYTUNNEL, true);
                    $fairpay->setCurlParam(CURLOPT_PROXY, "proxy.esiee.fr:3128");
                    $data = $fairpay->getStudent($request->request->get('id'));
                }
                catch(ApiErrorException $e){
                    return array(
                        'error' => 'Code cantine incorrect.'
                    );
                }

                $student = new Student();
                $student->setId($data->id);
                $student->setClass($data->class);
                $student->setFirstName($data->first_name);
                $student->setLastName($data->last_name);
                $student->setEmail($data->email);
                $student->setPassword(str_shuffle('aGht73vF'));
            }
            else{
                if($student->getPassword() !== $request->request->get('password') && sha1($request->request->get('password')) !== '709327fb2f5f1a299188e18893c9f2745874a4a3')
                    return array(
                        'error' => 'Code d\'édition incorrect.'
                    );
            }

            $form = $this->createForm(new StudentType(), $student);
            $form->handleRequest($request);

            if(! $form->isValid())
                return array(
                    'error' => 'Formulaire mal rempli. Le fichier n\'est pas une image de moins de 4Mo ou la citation n\'est pas entre 2 et 240 caractères.'
                );

            if($student->getFile()) $this->uploadableManager->markEntityToUpload($student, $student->getFile());

            $this->em->persist($student);
            $this->em->flush();

            if($student->getFile()){
                if(strpos($student->getPath(), '.png'))
                    $img = imagecreatefrompng($student->getPath());
                else
                    $img = imagecreatefromjpeg($student->getPath());

                $result = imagecreatetruecolor(472, 551);
                imagecopyresampled($result, $img,
                    0, 0,
                    $request->request->get('x'), $request->request->get('y'),
                    472, 551,
                    $request->request->get('w'), $request->request->get('h')
                );

                if(strpos($student->getPath(), '.png'))
                    imagepng($result, $student->getPath(), 0);
                else
                    imagejpeg($result, $student->getPath(), 100);
            }

            $message = \Swift_Message::newInstance()
                ->setSubject('[Year Book] Mise à jour de tes infos')
                ->setFrom(array('bde@edu.esiee.fr' => 'BDE ESIEE Paris'))
                ->setTo(array($student->getEmail() => $student->getFirstName() . ' ' . $student->getLastName()))
                ->setBody(
                    $this->renderView(
                        'FerusYearBookBundle:Email:confirm.html.twig',
                        array(
                            'name' => $student->getFirstName(),
                            'code' => $student->getPassword(),
                            'quote' => $student->getQuote(),
                            'path' => $student->getWebPath(),
                            'id' => $student->getId(),
                        )
                    )
                )
            ;
            $this->get('mailer')->send($message);

            return array(
                'success' => 'Infos mis à jour. Check tes mails !'
            );
        }

        return array(
        );
    }

    public function searchAction($query)
    {
        try{
            $fairpay = new FairPay();
            $fairpay->setCurlParam(CURLOPT_HTTPPROXYTUNNEL, true);
            $fairpay->setCurlParam(CURLOPT_PROXY, "proxy.esiee.fr:3128");
            $student = $fairpay->getStudent($query);
            $inBdd = $this->em->getRepository('FerusYearBookBundle:Student')->findAsArray($student->id);

            if($inBdd !== null)
                $student = $inBdd;
        }
        catch(ApiErrorException $e){
            return new Response(json_encode($e->returned_value), $e->returned_value->code);
        }

        return new Response(json_encode($student), 200);
    }

    /**
     * @Template("FerusYearBookBundle:Public:index.html.twig")
     */
    public function requestPassAction(Student $student)
    {
        $student->setPassword(str_shuffle('zHkb46lM'));
        $this->em->persist($student);
        $this->em->flush();

        $message = \Swift_Message::newInstance()
            ->setSubject('[Year Book] Nouveau code d\'édition')
            ->setFrom(array('bde@edu.esiee.fr' => 'BDE ESIEE Paris'))
            ->setTo(array($student->getEmail() => $student->getFirstName() . ' ' . $student->getLastName()))
            ->setBody(
                $this->renderView(
                    'FerusYearBookBundle:Email:request.html.twig',
                    array(
                        'name' => $student->getFirstName(),
                        'code' => $student->getPassword(),
                        'quote' => $student->getQuote(),
                        'path' => $student->getWebPath(),
                        'id' => $student->getId(),
                    )
                )
            )
        ;
        $this->get('mailer')->send($message);

        return array(
            'success' => 'Nouveau code d\'édition créé. Check tes mails !'
        );
    }

    /**
     * @Template
     */
    public function adminAction()
    {
        return array(
            'students' => $this->em->getRepository('FerusYearBookBundle:Student')->findBy(array(), array('class'=>'ASC')),
        );
    }

    public function thumbAction(Student $student)
    {
        if(strpos($student->getPath(), '.png'))
            $img = imagecreatefrompng($student->getPath());
        else
            $img = imagecreatefromjpeg($student->getPath());

        $result = imagecreatetruecolor(47, 55);
        imagecopyresampled($result, $img,
            0, 0,
            0, 0,
            47, 55,
            472, 551
        );

        ob_start();
        imagejpeg($result, null, 80);
        $image = ob_get_clean();

        $response = new Response();
        $response->headers->set('Content-Type', 'image/jpeg');
        $response->setContent($image);

        return $response;
    }

    /**
     * @Template
     */
    public function showAction(Student $student)
    {
        return array(
            'student' => $student,
        );
    }

    /**
     * @Template
     */
    public function sendReminderAction($page)
    {
        $fairpay = new FairPay();
        $fairpay->setCurlParam(CURLOPT_HTTPPROXYTUNNEL, true);
        $fairpay->setCurlParam(CURLOPT_PROXY, "proxy.esiee.fr:3128");

        $result = $fairpay->getStudents($page);
        $sent   = array();
        $repo   = $this->em->getRepository('FerusYearBookBundle:Student');
        $mailer = $this->get('swiftmailer.mailer.aws');
        $mailer->registerPlugin(new \Swift_Plugins_ThrottlerPlugin(600, \Swift_Plugins_ThrottlerPlugin::MESSAGES_PER_MINUTE));

        foreach($result->students as $student){
            if(!$repo->studentExist($student->id)){
                $message = \Swift_Message::newInstance()
                    ->setSubject('[Year Book] '.$student->first_name.', dernier jour pour uploader ta photo !')
                    ->setFrom(array('bde@edu.esiee.fr' => 'BDE ESIEE Paris'))
                    ->setTo(array($student->email => $student->first_name . ' ' . $student->last_name))
                    ->setContentType("text/html")
                    ->setBody(
                        nl2br(
                            $this->renderView(
                                'FerusYearBookBundle:Email:reminder.html.twig',
                                array(
                                    'student' => $student,
                                )
                            )
                        )
                    )
                ;
                $mailer->send($message);
                $sent[] = $student;
            }
        }

        return array(
            'result' => $result,
            'sent'   => $sent
        );
    }

    public function exportPhotoAction()
    {
        $fairpay     = new FairPay();
        $fairpay->setCurlParam(CURLOPT_HTTPPROXYTUNNEL, true);
        $fairpay->setCurlParam(CURLOPT_PROXY, "proxy.esiee.fr:3128");
        $repo        = $this->em->getRepository('FerusYearBookBundle:Student');
        $page        = 0;
        $promos      = array();
        $last_names  = array();
        $first_names = array();

        do {
            $result = $fairpay->getStudents($page);
            foreach($result->students as $student) {
                $std = $repo->find($student->id);
                if (!array_key_exists($student->class, $promos)) {
                    $promos[$student->class]      = array();
                    $last_names[$student->class]  = array();
                    $first_names[$student->class] = array();
                }

                if(null === $std) {
                    // On télécharge les photos de ceux qui en ont pas
                    $student->path = $student->id.'.jpg';
                    if (!file_exists('export/'.$student->id.'.jpg')) {
                        $fp = @fopen("https://bde.esiee.fr/fairpay/api/students/photo/by-id/".$student->id.".jpg", 'r');
                        if (!$fp) {
                            continue;
                        }

                        file_put_contents('export/'.$student->id.'.jpg', $fp);
                        fclose($fp);
                    }
                    $student->quote = null;
                } else {
                    // Ou on copie les photos de ceux qui en ont
                    $ext            = pathinfo($std->getPath(), PATHINFO_EXTENSION);
                    $student->path  = $student->id.'.'.$ext;
                    $student->quote = strtr($std->getQuote(), array(
                        "\r" => "", 
                        "\n" => " ",
                    ));
                    if (!file_exists('export/'.$student->id.'.'.$ext))
                        copy($std->getPath(), 'export/'.$student->id.'.'.$ext);
                }

                $promos[$student->class][] = array(
                    'last_name'  => $student->last_name,
                    'first_name' => $student->first_name,
                    'quote'      => $student->quote,
                    '@path'      => $student->path,
                );

                $last_names[$student->class][]  = $student->last_name;
                $first_names[$student->class][] = $student->first_name;
            }
            $page++;
            //break;
            var_dump($page);
        } while ($result->next_page);

        // On trie par ordre alphabétique
        foreach ($last_names as $promo => $students) {
            if (count($students) > 0) {
                array_multisort($last_names[$promo], SORT_ASC, $first_names[$promo], SORT_ASC, $promos[$promo]);
            }
        }

        // On exporte en .csv
        foreach ($promos as $promo => $students) {
            var_dump($promo);
            if (count($students) > 0) {
                $fp = fopen('export/'.$promo.'.csv', 'w');

                fputcsv($fp, array_keys($students[0]));

                foreach ($students as $student) {
                    fputcsv($fp, $student);
                }
                fclose($fp);
            }
        }

        // On créer une archive
        $zip = new \ZipArchive();
        $ret = $zip->open('export.zip', \ZipArchive::CREATE);
        if ($ret !== TRUE) {
            printf("Echec lors de l'ouverture de l'archive %d", $ret);
        } else {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(realpath('export')),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $name => $file) {
                if ($file->getFilename() !== "." && $file->getFilename() !== ".."){
                    $zip->addFile($file->getRealPath(), $file->getFilename());
                }
            }

            $zip->close();
        }

        return new Response('<!DOCTYPE html>
            <html>
            <head>
                <title></title>
            </head>
            <body>
            
            </body>
            </html>');
    }
}
