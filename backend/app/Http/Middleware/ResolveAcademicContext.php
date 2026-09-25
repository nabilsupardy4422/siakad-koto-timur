<?php

namespace App\Http\Middleware;

use App\Support\AcademicContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveAcademicContext
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $academicYearId = $request->integer('academic_year_id');

        $academicContext = AcademicContext::resolve(
            $academicYearId > 0 ? $academicYearId : null
        );

        $request->attributes->set(
            'academic_context',
            $academicContext
        );

        return $next($request);
    }
}