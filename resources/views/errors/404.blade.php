{{-- Requerimiento técnico: mensaje en español, sin detalles internos (el detalle queda en storage/logs). --}}
@extends('errors::minimal')

@section('title', 'Página no encontrada')
@section('code', '404')
@section('message', 'La página que busca no existe.')
