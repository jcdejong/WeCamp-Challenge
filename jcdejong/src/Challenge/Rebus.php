<?php

namespace Challenge;

use Doctrine\ORM\Query\QueryException;

class Rebus
{
    /** @var \Doctrine\ORM\EntityManager $em */
    private $em;

    private $word;
    private $language;

    private $rebusWord;
    private $leftPart;
    private $rightPart;
    private $searchPart;
    private $instructions = [];

    private $iteration = 0;

    public function __construct($word, $language, $entityManager)
    {
        $this->em = $entityManager;

        // remove all unwanted characters, we use [À-ž], so we also include ä ö ü é ß î etc :)
        $this->word = preg_replace('/[^\w\p{L}\p{N}\p{Pd}]/u', '', $word, -1);
        $this->word = utf8_encode(strtolower(utf8_decode($this->word))); // not suitable for every occasion, but it works for now..

        // @todo: check if language is available/loaded?
        $this->language = strtolower($language);

        $this->find();
        $this->getInstructions();
    }

    /**
     * Find a word that could resample the original if you add, subtract of replace characters
     * @todo fix case when no suitable word can be found in the database...
     * @todo never return words already used for other word in this session
     */
    private function find()
    {
        // count the iterations
        $this->iteration++;

        $partToFind = $this->word;

        // if word is only 1 char long, no need to subtract anything from this word :)
        if (mb_strlen($this->word) > 1) {

            // take something of from the left or the right
            $leftOrRight = rand(0,1);

            if ($leftOrRight) {
                $lengthToFind = mb_strlen($this->leftPart) + 1;
                $this->leftPart = mb_substr($this->word, 0, $lengthToFind);
                $partToFind = mb_substr($this->word, $lengthToFind, mb_strlen($this->word));
            } else {
                $lengthToFind = mb_strlen($this->rightPart) + 1;
                $this->rightPart = mb_substr($this->word, mb_strlen($this->word) - $lengthToFind, mb_strlen($this->word));
                $partToFind = mb_substr($this->word, 0, mb_strlen($this->word) - $lengthToFind);
            }
        }

        try {
            $repo = $this->em->getRepository('Entities\Word');
            $query = $repo->createQueryBuilder('w')
                ->select('w.word, LENGTH(w.word) AS wordLength')
                ->where('w.word LIKE :word')
                ->andWhere('LENGTH(w.word) > ' . mb_strlen($partToFind))
                ->andWhere('w.word <> :original')
                ->andWhere('w.language = :language')
                ->setParameter('word', '%' . $partToFind . '%')
                ->setParameter('original', $this->word)
                ->setParameter('language', $this->language)
                ->orderBy('wordLength', 'ASC')
                ->getQuery();
            $result = $query->getResult();

            $randomIndex = rand(0, count($result));
            $this->rebusWord = isset($result[$randomIndex]['word']) ? $result[$randomIndex]['word'] : false;

            $this->searchPart = $partToFind;
        } catch (QueryException $e) {
            echo $e->getMessage() . PHP_EOL;
            die();
        }

        if (!$this->rebusWord) {
            $this->find();
        }
    }

    /**
     * Get the original word
     * @return string
     */
    public function getWord()
    {
        return $this->word;
    }

    /**
     * Get the word used in the rebus
     * @return mixed
     */
    public function getRebus()
    {
        return $this->rebusWord;
    }

    /**
     * Get the instructions to change the rebus word into the word we are looking for
     * @return array
     */
    public function getInstructions()
    {
        if ($this->rebusWord == '') {
            echo 'ERROR - could not find rebus word' . PHP_EOL;
            return [];
        }

        // first do a preg_replace of the first occurrance, so we don't fuck up words with multiple search parts :)
        $pattern = '/'.preg_quote($this->searchPart, '/').'/';
        $rebusWord = preg_replace($pattern, '|', $this->rebusWord, 1);

        // explode what is left, so we know what to replace/remove.
        // $parts should always have 2 elements and will have the following result:
        // - first empty |test
        // - second empty test|
        // - both containing something test|test
        $parts = explode('|', $rebusWord);

        if (count($parts) != 2) {
            echo 'ERROR - you found a bug, please bother @jcdejong about this ;)' . PHP_EOL;
//            var_dump($this->word);
//            var_dump($this->rebusWord);
//            var_dump($this->leftPart);
//            var_dump($this->rightPart);
//            var_dump($this->searchPart);
//            var_dump($parts);
//            echo PHP_EOL;
            die();
        }

        if ('' == $parts[0] && '' != $this->leftPart) {
            $this->instructions[] = $this->leftPart . '+';
        }
        if ('' != $parts[0] && '' == $this->leftPart) {
            $this->instructions[] = '-' . $parts[0];
        }
        if ('' != $parts[0] && '' != $this->leftPart) {
            $this->instructions[] = $parts[0] . '=' . $this->leftPart;
        }

        if ('' == $parts[1] && '' != $this->rightPart) {
            $this->instructions[] = '+' . $this->rightPart;
        }
        if ('' != $parts[1] && '' == $this->rightPart) {
            $this->instructions[] = '-' . $parts[1];
        }
        if ('' != $parts[1] && '' != $this->rightPart) {
            $this->instructions[] = $parts[1] . '=' . $this->rightPart;
        }

        return $this->instructions;
    }

    /**
     * Dump the instructions to screen
     */
    public function dump()
    {
        echo '[' . $this->rebusWord. ']' . PHP_EOL;
        foreach ($this->instructions as $instruction) {
            echo str_pad($instruction, mb_strlen($this->rebusWord) + 2, ' ', STR_PAD_BOTH). PHP_EOL;
        }
    }
}