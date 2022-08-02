<?php
namespace Opencart\Admin\Controller\Tool;

class Migrate extends \Opencart\System\Engine\Controller
{
    public function index(): void {
        $this->load->language('tool/migrate');
        $this->document->setTitle($this->language->get('heading_title'));

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('tool/migrate', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['user_token'] = $this->session->data['user_token'];
        $this->load->model('tool/migrate');

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('tool/migrate', $data));
    }

    public function getListMigrate():void {
        $this->load->model('tool/migrate');
        $data = $this->model_tool_migrate->migrateDBScheme();
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($data));
    }
}