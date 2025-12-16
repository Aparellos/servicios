<?php
namespace FacturaScripts\Plugins\Servicios\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;

class EditTrabajoAT extends EditController
{
    public function getModelClassName(): string
    {
        return 'TrabajoAT';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['title'] = 'work';
        $data['icon'] = 'fa-solid fa-stethoscope';
        return $data;
    }

    public function assets(): void
    {
        parent::assets();
        $this->addScript('EditTrabajoAT');
    }

    public function createViews()
    {
        parent::createViews();

        $impuestos = [];
        $model = new \FacturaScripts\Dinamic\Model\Impuesto();
        foreach ($model->all() as $impuesto) {
            $impuestos[$impuesto->codimpuesto] = $impuesto->iva;
        }

        $this->addHtml('<script>var fs_impuestos = ' . json_encode($impuestos) . ';</script>');
    }

    public function run(): void
    {
        if ($this->request->get('action') === 'get_product_data') {
            $this->getProductData();
            return;
        }
        
        parent::run();
    }

    private function getProductData()
    {
        $reference = $this->request->get('reference');
        $variante = new \FacturaScripts\Dinamic\Model\Variante();
        $where = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('referencia', $reference)];
        
        if ($variante->loadFromCode('', $where)) {
            $producto = $variante->getProducto();
            $data = [
                'codimpuesto' => $producto->codimpuesto,
                'precio' => $variante->precio
            ];
            $this->response->setContent(json_encode($data));
            $this->response->headers->set('Content-Type', 'application/json');
        } else {
            $this->response->setContent(json_encode(['error' => 'Product not found']));
            $this->response->setStatusCode(404);
        }
    }
}
