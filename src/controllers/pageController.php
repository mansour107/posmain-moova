<?php
class PageController
{
    public function home(){return $this->render('home');}


    private function render($view)
    {
        ob_start();
        include __DIR__ . "/../views/{$view}.php";
        return ob_get_clean();
    }
}
