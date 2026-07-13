<?php

declare(strict_types=1);

namespace eseperio\verifactu\services;

use eseperio\verifactu\models\Breakdown;
use eseperio\verifactu\models\BreakdownDetail;
use eseperio\verifactu\models\ComputerSystem;
use eseperio\verifactu\models\InvoiceCancellation;
use eseperio\verifactu\models\InvoiceId;
use eseperio\verifactu\models\InvoiceQuery;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\models\LegalPerson;
use eseperio\verifactu\models\OtherID;
use eseperio\verifactu\models\RectificationBreakdown;

/**
 * Service for serializing invoice models to XML according to the SuministroInformacion.xsd schema.
 * This service generates XML directly without relying on model toXml methods.
 */
class InvoiceSerializer
{
    /** SuministroInformacion.xsd namespace */
    public const SF_NAMESPACE = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';

    /** SuministroLR.xsd namespace */
    public const SFLR_NAMESPACE = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';

    /** ConsultaLR.xsd namespace */
    public const QUERY_NAMESPACE = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/ConsultaLR.xsd';

    /** XML Digital Signature namespace */
    public const DS_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';

    /**
     * Serializes an InvoiceSubmission model to XML.
     * Directly creates XML without relying on model toXml methods.
     *
     * @param InvoiceSubmission $invoice The invoice to serialize
     * @param bool $validate Whether to validate the XML against the XSD schema
     * @return \DOMDocument The XML document
     * @throws \Exception If validation fails
     */
    public static function toInvoiceXml(InvoiceSubmission $invoice, bool $validate = false): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS(self::SF_NAMESPACE, 'sf:RegistroAlta');
        $doc->appendChild($root);

        $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IDVersion', '1.0'));

        $invoiceId = $invoice->getInvoiceId();
        if ($invoiceId) {
            $idFactura = $doc->createElementNS(self::SF_NAMESPACE, 'sf:IDFactura');
            $idFactura->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IDEmisorFactura', (string) $invoiceId->issuerNif));
            $idFactura->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NumSerieFactura', (string) $invoiceId->seriesNumber));
            $idFactura->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:FechaExpedicionFactura', self::formatDate((string) $invoiceId->getIssueDate())));
            $root->appendChild($idFactura);
        }

        if (!empty($invoice->externalRef)) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:RefExterna', (string) $invoice->externalRef));
        }

        $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NombreRazonEmisor', (string) $invoice->issuerName));

        if ($invoice->invoiceType) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:TipoFactura', (string) $invoice->invoiceType->value));
        }

        if ($invoice->rectificationType) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:TipoRectificativa', (string) $invoice->rectificationType->value));
        }

        $rectData = $invoice->getRectificationData();
        if (!empty($rectData['rectified'])) {
            $facturasRectificadas = $doc->createElementNS(self::SF_NAMESPACE, 'sf:FacturasRectificadas');
            foreach ($rectData['rectified'] as $rect) {
                $idFacturaRectificada = $doc->createElementNS(self::SF_NAMESPACE, 'sf:IDFacturaRectificada');
                $idFacturaRectificada->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IDEmisorFactura', (string) $rect['issuerNif']));
                $idFacturaRectificada->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NumSerieFactura', (string) $rect['seriesNumber']));
                $idFacturaRectificada->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:FechaExpedicionFactura', self::formatDate((string) $rect['issueDate'])));
                $facturasRectificadas->appendChild($idFacturaRectificada);
            }
            $root->appendChild($facturasRectificadas);
        }

        if (!empty($rectData['substituted'])) {
            $facturasSustituidas = $doc->createElementNS(self::SF_NAMESPACE, 'sf:FacturasSustituidas');
            foreach ($rectData['substituted'] as $subst) {
                $idFacturaSustituida = $doc->createElementNS(self::SF_NAMESPACE, 'sf:IDFacturaSustituida');
                $idFacturaSustituida->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IDEmisorFactura', (string) $subst['issuerNif']));
                $idFacturaSustituida->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NumSerieFactura', (string) $subst['seriesNumber']));
                $idFacturaSustituida->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:FechaExpedicionFactura', self::formatDate((string) $subst['issueDate'])));
                $facturasSustituidas->appendChild($idFacturaSustituida);
            }
            $root->appendChild($facturasSustituidas);
        }

        $rectBreakdown = $invoice->getRectificationBreakdown();
        if ($rectBreakdown) {
            $importeRectificacion = $doc->createElementNS(self::SF_NAMESPACE, 'sf:ImporteRectificacion');
            $importeRectificacion->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:BaseRectificada', (string) number_format((float) $rectBreakdown->rectifiedBase, 2, '.', '')));
            $importeRectificacion->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:CuotaRectificada', (string) number_format((float) $rectBreakdown->rectifiedTax, 2, '.', '')));
            if (!is_null($rectBreakdown->rectifiedEquivalenceSurcharge)) {
                $importeRectificacion->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:CuotaRecargoRectificado', (string) number_format((float) $rectBreakdown->rectifiedEquivalenceSurcharge, 2, '.', '')));
            }
            $root->appendChild($importeRectificacion);
        }

        if (!empty($invoice->getOperationDate())) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:FechaOperacion', self::formatDate((string) $invoice->getOperationDate())));
        }

        $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:DescripcionOperacion', (string) $invoice->operationDescription));

        if ($invoice->simplifiedInvoice) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:FacturaSimplificadaArt7273', (string) $invoice->simplifiedInvoice->value));
        }

        if ($invoice->invoiceWithoutRecipient) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:FacturaSinIdentifDestinatarioArt61d', (string) $invoice->invoiceWithoutRecipient->value));
        }

        if ($invoice->macrodata) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:Macrodato', (string) $invoice->macrodata->value));
        }

        if ($invoice->issuedBy) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:EmitidaPorTerceroODestinatario', (string) $invoice->issuedBy->value));
        }

        $thirdParty = $invoice->getThirdParty();
        if ($thirdParty) {
            $tercero = $doc->createElementNS(self::SF_NAMESPACE, 'sf:Tercero');
            $tercero->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NombreRazon', (string) $thirdParty->name));
            if (!empty($thirdParty->nif)) {
                $tercero->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NIF', (string) $thirdParty->nif));
            } elseif ($otherId = $thirdParty->getOtherId()) {
                $idOtro = $doc->createElementNS(self::SF_NAMESPACE, 'sf:IDOtro');
                if (!empty($otherId->countryCode)) {
                    $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:CodigoPais', (string) $otherId->countryCode));
                }
                if (!empty($otherId->idType)) {
                    $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IDType', (string) $otherId->idType));
                }
                if (!empty($otherId->id)) {
                    $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:ID', (string) $otherId->id));
                }
                $tercero->appendChild($idOtro);
            }
            $root->appendChild($tercero);
        }

        $recipients = $invoice->getRecipients();
        if (!empty($recipients)) {
            $destinatarios = $doc->createElementNS(self::SF_NAMESPACE, 'sf:Destinatarios');
            foreach ($recipients as $recipient) {
                $idDestinatario = $doc->createElementNS(self::SF_NAMESPACE, 'sf:IDDestinatario');
                $idDestinatario->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NombreRazon', (string) $recipient->name));
                if (!empty($recipient->nif)) {
                    $idDestinatario->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NIF', (string) $recipient->nif));
                } elseif ($otherId = $recipient->getOtherId()) {
                    $idOtro = $doc->createElementNS(self::SF_NAMESPACE, 'sf:IDOtro');
                    if (!empty($otherId->countryCode)) {
                        $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:CodigoPais', (string) $otherId->countryCode));
                    }
                    if (!empty($otherId->idType)) {
                        $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IDType', (string) $otherId->idType));
                    }
                    if (!empty($otherId->id)) {
                        $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:ID', (string) $otherId->id));
                    }
                    $idDestinatario->appendChild($idOtro);
                }
                $destinatarios->appendChild($idDestinatario);
            }
            $root->appendChild($destinatarios);
        }

        if ($invoice->coupon) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:Cupon', (string) $invoice->coupon->value));
        }

        // Desglose (required)
        $breakdown = $invoice->getBreakdown();
        if ($breakdown) {
            $desglose = $doc->createElementNS(self::SF_NAMESPACE, 'sf:Desglose');
            foreach ($breakdown->getDetails() as $detail) {
                $detalleDesglose = $doc->createElementNS(self::SF_NAMESPACE, 'sf:DetalleDesglose');
                if ($detail->taxType) {
                    $detalleDesglose->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:Impuesto', (string) $detail->taxType->value));
                }

                if ($detail->regimeKey) {
                    $detalleDesglose->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:ClaveRegimen', (string) $detail->regimeKey->value));
                }

                if ($detail->operationQualification) {
                    $detalleDesglose->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:CalificacionOperacion', (string) $detail->operationQualification->value));
                } elseif ($detail->exemptOperation) {
                    $detalleDesglose->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:OperacionExenta', (string) $detail->exemptOperation->value));
                }

                if (!is_null($detail->taxRate)) {
                    $detalleDesglose->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:TipoImpositivo', (string) number_format((float) $detail->taxRate, 2, '.', '')));
                }

                $detalleDesglose->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:BaseImponibleOimporteNoSujeto', (string) number_format((float) $detail->taxableBase, 2, '.', '')));

                if (!is_null($detail->costBasedTaxableBase)) {
                    $detalleDesglose->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:BaseImponibleACoste', (string) number_format((float) $detail->costBasedTaxableBase, 2, '.', '')));
                }

                if (!is_null($detail->taxAmount)) {
                    $detalleDesglose->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:CuotaRepercutida', (string) number_format((float) $detail->taxAmount, 2, '.', '')));
                }

                if (!is_null($detail->equivalenceSurchargeRate)) {
                    $detalleDesglose->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:TipoRecargoEquivalencia', (string) number_format((float) $detail->equivalenceSurchargeRate, 2, '.', '')));
                }

                if (!is_null($detail->equivalenceSurchargeAmount)) {
                    $detalleDesglose->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:CuotaRecargoEquivalencia', (string) number_format((float) $detail->equivalenceSurchargeAmount, 2, '.', '')));
                }

                $desglose->appendChild($detalleDesglose);
            }
            $root->appendChild($desglose);
        } else {
            $desglose = $doc->createElementNS(self::SF_NAMESPACE, 'sf:Desglose');
            $detalleDesglose = $doc->createElementNS(self::SF_NAMESPACE, 'sf:DetalleDesglose');
            // Schema requires CalificacionOperacion or OperacionExenta before BaseImponible
            $detalleDesglose->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:CalificacionOperacion', 'S1'));
            $base = (float) $invoice->totalAmount - (float) $invoice->taxAmount;
            $detalleDesglose->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:BaseImponibleOimporteNoSujeto', (string) number_format($base, 2, '.', '')));
            if (!is_null($invoice->taxAmount)) {
                $detalleDesglose->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:CuotaRepercutida', (string) number_format((float) $invoice->taxAmount, 2, '.', '')));
            }
            $desglose->appendChild($detalleDesglose);
            $root->appendChild($desglose);
        }

        $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:CuotaTotal', (string) number_format((float) $invoice->taxAmount, 2, '.', '')));

        $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:ImporteTotal', (string) number_format((float) $invoice->totalAmount, 2, '.', '')));

        $encadenamiento = $doc->createElementNS(self::SF_NAMESPACE, 'sf:Encadenamiento');

        $chaining = $invoice->getChaining();
        if ($chaining) {
            if ($chaining->firstRecord === 'S') {
                $encadenamiento->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:PrimerRegistro', 'S'));
            } else {
                $previousInvoice = $chaining->getPreviousInvoice();
                if ($previousInvoice) {
                    $registroAnterior = $doc->createElementNS(self::SF_NAMESPACE, 'sf:RegistroAnterior');
                    $registroAnterior->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IDEmisorFactura', (string) $previousInvoice->issuerNif));
                    $registroAnterior->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NumSerieFactura', (string) $previousInvoice->seriesNumber));
                    $registroAnterior->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:FechaExpedicionFactura', self::formatDate((string) $previousInvoice->getIssueDate())));
                    $registroAnterior->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:Huella', (string) $previousInvoice->hash));
                    $encadenamiento->appendChild($registroAnterior);
                } else {
                    $encadenamiento->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:PrimerRegistro', 'S'));
                }
            }
        } else {
            $encadenamiento->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:PrimerRegistro', 'S'));
        }

        $root->appendChild($encadenamiento);

        $computerSystem = $invoice->getSystemInfo();
        $invoiceIdForDefaults = $invoice->getInvoiceId();
        $root->appendChild(self::buildSistemaInformatico($doc, $computerSystem, $invoiceIdForDefaults?->issuerNif));

        if (!empty($invoice->recordTimestamp)) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:FechaHoraHusoGenRegistro', (string) $invoice->recordTimestamp));
        }
        if (!empty($invoice->invoiceAgreementNumber)) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NumRegistroAcuerdoFacturacion', (string) $invoice->invoiceAgreementNumber));
        }
        if (!empty($invoice->systemAgreementId)) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IdAcuerdoSistemaInformatico', (string) $invoice->systemAgreementId));
        }
        if ($invoice->hashType) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:TipoHuella', (string) $invoice->hashType->value));
        }
        if (!empty($invoice->hash)) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:Huella', (string) $invoice->hash));
        }

        if ($validate) {
            self::validateXml($doc, __DIR__ . '/../schemes/SuministroInformacion.xsd');
        }

        return $doc;
    }

    /**
     * Serializes an InvoiceCancellation model to XML.
     */
    public static function toCancellationXml(InvoiceCancellation $cancellation, bool $validate = false): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS(self::SF_NAMESPACE, 'sf:RegistroAnulacion');
        $doc->appendChild($root);

        $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IDVersion', '1.0'));

        $invoiceId = $cancellation->getInvoiceId();
        if ($invoiceId) {
            $idFactura = $doc->createElementNS(self::SF_NAMESPACE, 'sf:IDFactura');
            $idFactura->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IDEmisorFacturaAnulada', (string) $invoiceId->issuerNif));
            $idFactura->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NumSerieFacturaAnulada', (string) $invoiceId->seriesNumber));
            $idFactura->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:FechaExpedicionFacturaAnulada', self::formatDate((string) $invoiceId->getIssueDate())));
            $root->appendChild($idFactura);
        }

        if (!empty($cancellation->externalReference)) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:RefExterna', (string) $cancellation->externalReference));
        }
        if ($cancellation->noPreviousRecord) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:SinRegistroPrevio', (string) $cancellation->noPreviousRecord->value));
        }
        if ($cancellation->previousRejection) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:RechazoPrevio', (string) $cancellation->previousRejection->value));
        }
        if ($cancellation->generator) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:GeneradoPor', (string) $cancellation->generator->value));
        }

        $generatorData = $cancellation->getGeneratorData();
        if ($generatorData) {
            $generador = $doc->createElementNS(self::SF_NAMESPACE, 'sf:Generador');
            $generador->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NombreRazon', (string) $generatorData->name));
            if (!empty($generatorData->nif)) {
                $generador->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NIF', (string) $generatorData->nif));
            } elseif ($otherId = $generatorData->getOtherId()) {
                $idOtro = $doc->createElementNS(self::SF_NAMESPACE, 'sf:IDOtro');
                if (!empty($otherId->countryCode)) {
                    $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:CodigoPais', (string) $otherId->countryCode));
                }
                if (!empty($otherId->idType)) {
                    $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IDType', (string) $otherId->idType));
                }
                if (!empty($otherId->id)) {
                    $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:ID', (string) $otherId->id));
                }
                $generador->appendChild($idOtro);
            }
            $root->appendChild($generador);
        }

        $encadenamiento = $doc->createElementNS(self::SF_NAMESPACE, 'sf:Encadenamiento');
        $chaining = $cancellation->getChaining();
        if ($chaining) {
            if ($chaining->firstRecord === 'S') {
                $encadenamiento->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:PrimerRegistro', 'S'));
            } else {
                $previousInvoice = $chaining->getPreviousInvoice();
                if ($previousInvoice) {
                    $registroAnterior = $doc->createElementNS(self::SF_NAMESPACE, 'sf:RegistroAnterior');
                    $registroAnterior->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IDEmisorFactura', (string) $previousInvoice->issuerNif));
                    $registroAnterior->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NumSerieFactura', (string) $previousInvoice->seriesNumber));
                    $registroAnterior->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:FechaExpedicionFactura', self::formatDate((string) $previousInvoice->getIssueDate())));
                    $registroAnterior->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:Huella', (string) $previousInvoice->hash));
                    $encadenamiento->appendChild($registroAnterior);
                } else {
                    $encadenamiento->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:PrimerRegistro', 'S'));
                }
            }
        } else {
            $encadenamiento->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:PrimerRegistro', 'S'));
        }
        $root->appendChild($encadenamiento);

        $computerSystem = $cancellation->getSystemInfo();
        $invoiceIdForDefaults = $cancellation->getInvoiceId();
        $root->appendChild(self::buildSistemaInformatico($doc, $computerSystem, $invoiceIdForDefaults?->issuerNif));

        if (!empty($cancellation->recordTimestamp)) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:FechaHoraHusoGenRegistro', (string) $cancellation->recordTimestamp));
        }
        if ($cancellation->hashType) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:TipoHuella', (string) $cancellation->hashType->value));
        }
        if (!empty($cancellation->hash)) {
            $root->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:Huella', (string) $cancellation->hash));
        }

        if ($validate) {
            self::validateXml($doc, __DIR__ . '/../schemes/SuministroInformacion.xsd');
        }

        return $doc;
    }

    /**
     * Serializes an InvoiceQuery model to XML.
     */
    public static function toQueryXml(InvoiceQuery $query, bool $validate = true): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS(self::QUERY_NAMESPACE, 'sf:ConsultaFactuSistemaFacturacion');
        $doc->appendChild($root);

        $cabecera = $doc->createElementNS(self::QUERY_NAMESPACE, 'sf:Cabecera');
        $cabecera->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IDVersion', '1.0'));
        $root->appendChild($cabecera);
        $issuerparty = $query->getIssuerparty();
        $obligadoEmision = $doc->createElementNS(self::SF_NAMESPACE, 'sf:ObligadoEmision');
        $cabecera->appendChild($obligadoEmision);
        $obligadoEmision->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NombreRazon', (string) $issuerparty['name']));
        $obligadoEmision->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NIF', (string) $issuerparty['nif']));

        $filtro = $doc->createElementNS(self::QUERY_NAMESPACE, 'sf:FiltroConsulta');
        $root->appendChild($filtro);

        $periodoImputacion = $doc->createElementNS(self::QUERY_NAMESPACE, 'sf:PeriodoImputacion');
        $periodoImputacion->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:Ejercicio', (string) $query->year));
        $periodoImputacion->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:Periodo', (string) $query->period));
        $filtro->appendChild($periodoImputacion);

        if (!empty($query->seriesNumber)) {
            $filtro->appendChild(self::createTextElement($doc, self::QUERY_NAMESPACE, 'sf:NumSerieFactura', (string) $query->seriesNumber));
        }

        $counterparty = $query->getCounterparty();
        if (!empty($counterparty) && is_array($counterparty)) {
            $contraparte = $doc->createElementNS(self::QUERY_NAMESPACE, 'sf:Contraparte');
            if (!empty($counterparty['name'])) {
                $contraparte->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NombreRazon', (string) $counterparty['name']));
            }
            if (!empty($counterparty['nif'])) {
                $contraparte->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NIF', (string) $counterparty['nif']));
            }
            if (!empty($counterparty['otherId'])) {
                if (is_array($counterparty['otherId'])) {
                    $idOtro = $doc->createElementNS(self::SF_NAMESPACE, 'sf:IDOtro');
                    if (!empty($counterparty['otherId']['countryCode'])) {
                        $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:CodigoPais', (string) $counterparty['otherId']['countryCode']));
                    }
                    if (!empty($counterparty['otherId']['idType'])) {
                        $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IDType', (string) $counterparty['otherId']['idType']));
                    }
                    if (!empty($counterparty['otherId']['id'])) {
                        $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:ID', (string) $counterparty['otherId']['id']));
                    }
                    $contraparte->appendChild($idOtro);
                }
            }
            $filtro->appendChild($contraparte);
        }

        if (!empty($query->getIssueDate())) {
            $fechaWrapper = $doc->createElementNS(self::QUERY_NAMESPACE, 'sf:FechaExpedicionFactura');
            $fechaWrapper->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:FechaExpedicionFactura', self::formatDate((string) $query->getIssueDate())));
            $filtro->appendChild($fechaWrapper);
        }

        if (!empty($query->externalRef)) {
            $filtro->appendChild(self::createTextElement($doc, self::QUERY_NAMESPACE, 'sf:RefExterna', (string) $query->externalRef));
        }

        if ($validate) {
            self::validateXml($doc, __DIR__ . '/../schemes/ConsultaLR.xsd');
        }

        return $doc;
    }

    /**
     * Converts a date string from YYYY-MM-DD to DD-MM-YYYY format as required by the AEAT schema.
     * If the value is already in DD-MM-YYYY format, it is returned unchanged.
     * Any other format is returned as-is; validation of the format should be done prior to calling this method.
     *
     * @param string $date Date string in YYYY-MM-DD or DD-MM-YYYY format
     * @return string Date string in DD-MM-YYYY format
     */
    /**
     * Creates a namespaced element with properly escaped text content.
     * Unlike createElementNS(..., $value), this uses a dedicated DOMText node
     * so that &amp;, &lt;, &gt;, quotes and apostrophes are correctly encoded.
     */
    private static function createTextElement(\DOMDocument $doc, string $namespace, string $qualifiedName, string $value): \DOMElement
    {
        $element = $doc->createElementNS($namespace, $qualifiedName);
        $element->appendChild($doc->createTextNode($value));
        return $element;
    }

    public static function formatDate(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            [$year, $month, $day] = explode('-', $date);
            return "{$day}-{$month}-{$year}";
        }

        // Already in DD-MM-YYYY format or unrecognized — return unchanged
        return $date;
    }

    public static function validateXml(\DOMDocument $doc, string $schemaPath): void
    {
        libxml_use_internal_errors(true);
        if (!$doc->schemaValidate($schemaPath)) {
            $errors = "";
            foreach (libxml_get_errors() as $error) {
                $errors .= $error->message . PHP_EOL;
            }
            throw new \Exception('The XML generated does not comply with schema: ' . $errors);
        }
    }

    public static function wrapXmlWithRegFactuStructure(\DOMDocument $doc, string $nif, string $name, ?string $fechaFinVeriFactu = null): \DOMDocument
    {
        $newDoc = new \DOMDocument('1.0', 'UTF-8');
        $newDoc->formatOutput = true;

        $root = $newDoc->createElementNS(self::SFLR_NAMESPACE, 'sfLR:RegFactuSistemaFacturacion');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sf', self::SF_NAMESPACE);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', self::DS_NAMESPACE);
        $newDoc->appendChild($root);

        $cabecera = $newDoc->createElementNS(self::SFLR_NAMESPACE, 'sfLR:Cabecera');
        $root->appendChild($cabecera);

        $obligadoEmision = $newDoc->createElementNS(self::SF_NAMESPACE, 'sf:ObligadoEmision');
        $cabecera->appendChild($obligadoEmision);
        $obligadoEmision->appendChild(self::createTextElement($newDoc, self::SF_NAMESPACE, 'sf:NombreRazon', (string) $name));
        $obligadoEmision->appendChild(self::createTextElement($newDoc, self::SF_NAMESPACE, 'sf:NIF', (string) $nif));

        if (!empty($fechaFinVeriFactu)) {
            $remisionVoluntaria = $newDoc->createElementNS(self::SF_NAMESPACE, 'sf:RemisionVoluntaria');
            $remisionVoluntaria->appendChild(self::createTextElement($newDoc, self::SF_NAMESPACE, 'sf:FechaFinVeriFactu', (string) $fechaFinVeriFactu));
            $cabecera->appendChild($remisionVoluntaria);
        }

        $registroFactura = $newDoc->createElementNS(self::SFLR_NAMESPACE, 'sfLR:RegistroFactura');
        $root->appendChild($registroFactura);

        $imported = $newDoc->importNode($doc->documentElement, true);
        $registroFactura->appendChild($imported);

        return $newDoc;
    }

    private static function buildSistemaInformatico(\DOMDocument $doc, ?ComputerSystem $system, ?string $issuerNifForDefault): \DOMElement
    {
        $sistemaInformatico = $doc->createElementNS(self::SF_NAMESPACE, 'sf:SistemaInformatico');
        $providerName = $system?->providerName ?? 'Proveedor Sistema';
        $sistemaInformatico->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NombreRazon', (string) $providerName));

        $providerId = $system?->getProviderId();
        if ($providerId && !empty($providerId->nif)) {
            $sistemaInformatico->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NIF', (string) $providerId->nif));
        } elseif ($providerId && ($otherId = $providerId->getOtherId())) {
            $idOtro = $doc->createElementNS(self::SF_NAMESPACE, 'sf:IDOtro');
            if (!empty($otherId->countryCode)) {
                $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:CodigoPais', (string) $otherId->countryCode));
            }
            if (!empty($otherId->idType)) {
                $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IDType', (string) $otherId->idType));
            }
            if (!empty($otherId->id)) {
                $idOtro->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:ID', (string) $otherId->id));
            }
            $sistemaInformatico->appendChild($idOtro);
        } else {
            $fallbackNif = $issuerNifForDefault ?: '12345678Z';
            $sistemaInformatico->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NIF', (string) $fallbackNif));
        }

        $nombreSistema = $system?->systemName ?? 'Sistema';
        $idSistema = $system?->systemId ?? '01';
        $version = $system?->version ?? '1.0';
        $numInstalacion = $system?->installationNumber ?? '1';
        $soloVerifactu = $system?->onlyVerifactu?->value ?? 'S';
        $multiOT = $system?->multipleObligations?->value ?? 'N';
        $indicadorMulti = $system?->hasMultipleObligations?->value ?? 'N';

        $sistemaInformatico->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NombreSistemaInformatico', (string) $nombreSistema));
        $sistemaInformatico->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IdSistemaInformatico', (string) $idSistema));
        $sistemaInformatico->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:Version', (string) $version));
        $sistemaInformatico->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:NumeroInstalacion', (string) $numInstalacion));
        $sistemaInformatico->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:TipoUsoPosibleSoloVerifactu', (string) $soloVerifactu));
        $sistemaInformatico->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:TipoUsoPosibleMultiOT', (string) $multiOT));
        $sistemaInformatico->appendChild(self::createTextElement($doc, self::SF_NAMESPACE, 'sf:IndicadorMultiplesOT', (string) $indicadorMulti));

        return $sistemaInformatico;
    }
}
