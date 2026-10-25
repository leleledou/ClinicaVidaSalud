{{-- Requerimiento técnico: mensaje en español, sin detalles internos (el detalle queda en storage/logs). --}}
@extends('errors::minimal')

@section('title', 'Servicio no disponible')
@section('code', '503')
@section('message', 'El sistema no está disponible en este momento. Intente más tarde.')
