<?php
/**
 * This file is part of Servicios plugin for FacturaScripts
 * Copyright (C) 2020-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\Servicios\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Agente;
use FacturaScripts\Core\Model\Base;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\ServicioAT as DinServicioAT;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * Description of TrabajoAT
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
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

    /** @var float */
    public $cantidad;

    /** @var string */
    public $codagente;

    /** @var string */
    public $descripcion;

    /** @var int */
    public $estado;

    /** @var string */
    public $fechafin;

    /** @var string */
    public $fechainicio;

    /** @var string */
    public $horafin;

    /** @var string */
    public $horainicio;

    /** @var int */
    public $idservicio;

    /** @var int */
    public $idtrabajo;

    /** @var string */
    public $nick;

    /** @var string */
    public $observaciones;

    /** @var float */
    public $precio;

    /** @var string */
    public $referencia;

    /** @var string */
    protected $messageLog = 'updated-model';

    public function clear()
    {
        parent::clear();
        $this->cantidad = 1.0;
        $this->estado = (int)Tools::settings('servicios', 'workstatus');
        $this->fechainicio = Tools::date();
        $this->horainicio = Tools::hour();
        $this->precio = 0.0;
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

    public function install(): string
    {
        new DinServicioAT();
        new Variante();
        new Agente();

        return parent::install();
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
            $this->precio = empty($this->precio) ? $variante->precio : $this->precio;
        }

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
        // añadimos el cambio al log
        $this->messageLog = Tools::lang()->trans('changed-quantity-work-to', [
            '%reference%' => $this->referencia,
            '%oldQuantity%' => $this->previousData['cantidad'],
            '%newQuantity%' => $this->cantidad,
            '%work%' => $this->idtrabajo
        ]);
    }

    protected function onChangeReferencia()
    {
        // añadimos el cambio al log
        $this->messageLog = Tools::lang()->trans('changed-referencia-work-to', [
            '%oldReference%' => $this->previousData['referencia'],
            '%newReference%' => $this->referencia,
            '%work%' => $this->idtrabajo
        ]);
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
        // ¿El control de stock en servicios está desactivado?
        if (Tools::settings('servicios', 'disablestockmanagement', false)) {
            return;
        }

        $restan = [self::STATUS_MAKE_INVOICE, self::STATUS_MAKE_DELIVERY_NOTE, self::STATUS_NONE];
        $sumar = in_array($estado, $restan, true) ? $cantidad : 0;
        if (empty($referencia) || empty($cantidad) || empty($sumar)) {
            return;
        }

        // ¿El producto controla stock?
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
            // no hay registro de stock, lo creamos
            $stock->referencia = $referencia;
            $stock->codalmacen = $this->getServicio()->codalmacen;
        }

        $stock->cantidad += $sumar;
        $stock->save();
    }
}
