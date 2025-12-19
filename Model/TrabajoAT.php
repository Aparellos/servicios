<?php
/**
 * This file is part of Servicios plugin for FacturaScripts
 */

namespace FacturaScripts\Plugins\Servicios\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\ServicioAT as DinServicioAT;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\Variante;

class TrabajoAT extends Base\ModelOnChangeClass
{
    use Base\ModelTrait;

    const STATUS_NONE = 0;
    const STATUS_MAKE_INVOICE = 1;
    const STATUS_INVOICED = 2;
    const STATUS_MAKE_DELIVERY_NOTE = 3;
    const STATUS_DELIVERY_NOTE = 4;
    const STATUS_MAKE_ESTIMATION = 5;
    const STATUS_ESTIMATION = 6;
    const STATUS_SUBTRACT_STOCK = -1;

    public $cantidad;
    public $codagente;
    public $descripcion;
    public $estado;
    public $fechafin;
    public $fechainicio;
    public $horafin;
    public $horainicio;
    public $idservicio;
    public $idtrabajo;
    public $iva;
    public $nick;
    public $observaciones;
    public $precio;
    public $referencia;
    public $pvp_con_iva; // <<-- PROPIEDAD A CALCULAR

    protected $messageLog = 'updated-model';

    public function clear()
    {
        parent::clear();
        $this->cantidad = 1.0;
        $this->estado = (int)Tools::settings('servicios', 'workstatus');
        $this->fechainicio = Tools::date();
        $this->horainicio = Tools::hour();
        $this->horainicio = Tools::hour();
        $this->iva = 0.0;
        $this->precio = 0.0;
        $this->pvp_con_iva = 0.0;
    }

    public function getServicio(): DinServicioAT
    {
        $servicio = new DinServicioAT();
        $servicio->loadFromCode($this->idservicio);
        return $servicio;
    }

    public static function getAvailableStatus(): array
    {
        return [
            self::STATUS_NONE => Tools::lang()->trans('do-nothing'),
            self::STATUS_MAKE_INVOICE => Tools::lang()->trans('make-invoice'),
            self::STATUS_INVOICED => Tools::lang()->trans('invoiced'),
            self::STATUS_MAKE_DELIVERY_NOTE => Tools::lang()->trans('make-delivery-note'),
            self::STATUS_DELIVERY_NOTE => Tools::lang()->trans('delivery-note'),
            self::STATUS_MAKE_ESTIMATION => Tools::lang()->trans('make-estimation'),
            self::STATUS_ESTIMATION => Tools::lang()->trans('estimation'),
        ];
    }

    public function getVariante(): Variante
    {
        $variante = new Variante();
        $where = [new DataBaseWhere('referencia', $this->referencia)];
        $variante->loadFromCode('', $where);
        return $variante;
    }

    public static function primaryColumn(): string
    {
        return 'idtrabajo';
    }

    public static function tableName(): string
    {
        return 'serviciosat_trabajos';
    }

    public function test(): bool
    {
        foreach (['descripcion', 'observaciones', 'referencia'] as $field) {
            $this->{$field} = Tools::noHtml($this->{$field});
        }

        if (empty($this->horafin)) {
            $this->horafin = null;
        }

        if ($this->referencia) {
            $variante = $this->getVariante();
            $this->descripcion = empty($this->descripcion) ? $variante->description() : $this->descripcion;
            
            // Always update tax data from product to ensure consistency
            $producto = $variante->getProducto();
            if ($producto->codimpuesto) {
                $this->codimpuesto = $producto->codimpuesto;
            }
        } else {
            // Si no hay referencia, limpiamos los datos de impuestos para permitir el fallback al valor por defecto
            $this->codimpuesto = null;
            $this->iva = null;
        }

        if (empty($this->codimpuesto)) {
            $this->codimpuesto = null;
        }

        // Always update IVA from codimpuesto
        if ($this->codimpuesto) {
            $impuesto = new \FacturaScripts\Dinamic\Model\Impuesto();
            if ($impuesto->loadFromCode($this->codimpuesto)) {
                $this->iva = $impuesto->iva;
            }
        }

        // Fallback: Si el precio es 0 pero el PVP está definido, calculamos el precio desde el PVP
        if (empty($this->precio) && !empty($this->pvp_con_iva)) {
            $iva = is_null($this->iva) ? 0 : $this->iva;
            
            // Si el IVA es 0, intentamos obtenerlo del impuesto por defecto
            if ($iva === 0.0 && empty($this->codimpuesto)) {
                $defaultCodImpuesto = Tools::settings('default', 'codimpuesto');
                if ($defaultCodImpuesto) {
                    $impuesto = new \FacturaScripts\Dinamic\Model\Impuesto();
                    if ($impuesto->loadFromCode($defaultCodImpuesto)) {
                        $iva = $impuesto->iva;
                    }
                }
            }
            
            // Fallback final a 21 si sigue siendo 0 (y no se ha establecido explícitamente a 0)
            if ($iva === 0.0 && empty($this->codimpuesto)) {
                $iva = 21;
            }
            
            $this->precio = $this->pvp_con_iva / (1 + $iva / 100);
        }

        // --- CORRECCIÓN CRÍTICA: Asignación forzada para que el valor esté disponible al cargar la vista
        $this->pvp_con_iva = $this->getPvpConIva();
        // ---

        return parent::test();
    }



    public function url(string $type = 'auto', string $list = 'ListServicioAT'): string
    {
        return empty($this->idservicio) ? parent::url($type, $list) : $this->getServicio()->url();
    }

    protected function onChange($field): bool
    {
        switch ($field) {
            case 'cantidad':
            case 'estado':
            case 'referencia':
                $this->updateStock($this->previousData['referencia'], $this->previousData['cantidad'], $this->previousData['estado']);
                $this->updateStock($this->referencia, 0 - $this->cantidad, $this->estado);
                break;
        }

        return parent::onChange($field);
    }

    protected function onChangeCantidad()
    {
        $this->messageLog = Tools::lang()->trans('changed-quantity-work-to', [
            '%reference%' => $this->referencia,
            '%oldQuantity%' => $this->previousData['cantidad'],
            '%newQuantity%' => $this->cantidad,
            '%work%' => $this->idtrabajo
        ]);
    }

    protected function onChangeReferencia()
    {
        $this->messageLog = Tools::lang()->trans('changed-referencia-work-to', [
            '%oldReference%' => $this->previousData['referencia'],
            '%newReference%' => $this->referencia,
            '%work%' => $this->idtrabajo
        ]);

        if ($this->referencia) {
            $variante = $this->getVariante();
            $producto = $variante->getProducto();
            if ($producto->codimpuesto) {
                $this->codimpuesto = $producto->codimpuesto;
                $impuesto = new \FacturaScripts\Dinamic\Model\Impuesto();
                if ($impuesto->loadFromCode($this->codimpuesto)) {
                    $this->iva = $impuesto->iva;
                }
            }
        } else {
            // Limpiamos los datos de impuestos si se borra la referencia
            $this->codimpuesto = null;
            $this->iva = null;
        }
    }

    protected function onDelete()
    {
        parent::onDelete();
        $this->updateStock($this->referencia, $this->cantidad, $this->estado);
    }

    protected function onInsert()
    {
        $this->updateStock($this->referencia, 0 - $this->cantidad, $this->estado);

        $service = $this->getServicio();
        $service->calculatePriceNet();

        // Al insertar, actualizamos el PVP con IVA
        $this->pvp_con_iva = $this->getPvpConIva();

        $log = new ServicioATLog();
        $log->idservicio = $this->idservicio;
        $log->message = Tools::lang()->trans('new-work-created', [
            '%key%' => $this->primaryColumnValue(),
            '%service-key%' => $service->idservicio
        ]);
        $log->context = $this;
        $log->save();

        parent::onInsert();
    }

    protected function onUpdate()
    {
        $service = $this->getServicio();
        $service->calculatePriceNet();

        // Al actualizar, actualizamos el PVP con IVA
        $this->pvp_con_iva = $this->getPvpConIva();

        if ($this->cantidad != $this->previousData['cantidad']) {
            $this->onChangeCantidad();
        }

        if ($this->referencia != $this->previousData['referencia']) {
            $this->onChangeReferencia();
        }

        $log = new ServicioATLog();
        $log->idservicio = $this->idservicio;
        $log->message = $this->messageLog;
        $log->context = $this;
        $log->save();

        parent::onUpdate();
    }

    protected function setPreviousData(array $fields = [])
    {
        $more = ['cantidad', 'estado', 'referencia'];
        parent::setPreviousData(array_merge($fields, $more));
    }

    protected function updateStock(?string $referencia, float $cantidad, int $estado): void
    {
        if (Tools::settings('servicios', 'disablestockmanagement', false)) {
            return;
        }

        $restan = [self::STATUS_MAKE_INVOICE, self::STATUS_MAKE_DELIVERY_NOTE, self::STATUS_NONE];
        $sumar = in_array($estado, $restan, true) ? $cantidad : 0;
        if (empty($referencia) || empty($cantidad) || empty($sumar)) {
            return;
        }

        $producto = $this->getVariante()->getProducto();
        if ($producto->nostock) {
            return;
        }

        $stock = new Stock();
        $where = [
            new DataBaseWhere('referencia', $referencia),
            new DataBaseWhere('codalmacen', $this->getServicio()->codalmacen)
        ];
        if (false === $stock->loadFromCode('', $where)) {
            $stock->referencia = $referencia;
            $stock->codalmacen = $this->getServicio()->codalmacen;
        }

        $stock->cantidad += $sumar;
        $stock->save();
    }

    /**
     * Devuelve el precio con IVA (PVP)
     */
    public function getPvpConIva(): float
    {
        $iva = $this->iva;
        if (is_null($iva) || (empty($this->referencia) && empty($this->codimpuesto))) {
            $codImpuesto = $this->codimpuesto ?: Tools::settings('default', 'codimpuesto');
            if ($codImpuesto) {
                $impuesto = new \FacturaScripts\Dinamic\Model\Impuesto();
                if ($impuesto->loadFromCode($codImpuesto)) {
                    $iva = $impuesto->iva;
                }
            }
        }
        
        // Fallback al valor por defecto si sigue siendo nulo
        if (is_null($iva)) {
            $iva = 21;
        }
        
        return round($this->precio * (1 + $iva / 100), 2);
    }
}
