THIRD PARTY NOTICES — Antevemus ASpecification Revolt
=====================================================

This file lists the notices, attributions, and license texts of software
relevant to this package. The runtime dependencies are installed by Composer
as separate packages, each with its own LICENSE; their texts are reproduced
here for convenience.

-------------------------------------------------------------------------------
1. ANTEVEMUS ASPECIFICATION REVOLT — MIT LICENSE
-------------------------------------------------------------------------------

Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda.

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

-------------------------------------------------------------------------------
2. RUNTIME DEPENDENCY — ANTEVEMUS ASPECIFICATION
-------------------------------------------------------------------------------

The core package, first-party software from the same author, licensed under
the MIT License, with its own LICENSE, NOTICE.md and THIRD_PARTY_NOTICES.md.
It brings antevemus/adeep-property-access (^1.0, MIT, same author).

    Package: antevemus/aspecification (^2.6)
    Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
    Website: https://github.com/antevemus-it/Antevemus.ASpecification

-------------------------------------------------------------------------------
3. RUNTIME DEPENDENCY — REVOLT EVENT LOOP (MIT)
-------------------------------------------------------------------------------

    Package: revolt/event-loop (^1.0)
    Website: https://github.com/revoltphp/event-loop


    The MIT License (MIT)

    Copyright (c) 2021-2023 Revolt (Aaron Piotrowski, Cees-Jan Kiewiet, Christian Lück, Niklas Keller, and contributors)
    Copyright (c) 2015-2021 amphp (Daniel Lowrey, Aaron Piotrowski, Niklas Keller, Bob Weinand, and contributors)
    Copyright (c) 2012-2021 ReactPHP (Christian Lück, Cees-Jan Kiewiet, Jan Sorgalla, Chris Boden, Igor Wiedler, and contributors)
    Copyright (c) 2016 PHP Asynchronous Interoperability Group

    Permission is hereby granted, free of charge, to any person obtaining a copy
    of this software and associated documentation files (the "Software"), to deal
    in the Software without restriction, including without limitation the rights
    to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
    copies of the Software, and to permit persons to whom the Software is
    furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in all
    copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
    AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
    OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
    SOFTWARE.

-------------------------------------------------------------------------------
4. RUNTIME DEPENDENCY — AMPHP AMP (MIT)
-------------------------------------------------------------------------------

    Package: amphp/amp (^3.0)
    Website: https://github.com/amphp/amp

    The MIT License (MIT)

    Copyright (c) 2015-2022 amphp (Daniel Lowrey, Aaron Piotrowski, Niklas Keller, Bob Weinand, and contributors)
    Copyright (c) 2016 PHP Asynchronous Interoperability Group

    Permission is hereby granted, free of charge, to any person obtaining a copy
    of this software and associated documentation files (the "Software"), to deal
    in the Software without restriction, including without limitation the rights
    to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
    copies of the Software, and to permit persons to whom the Software is
    furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in all
    copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
    AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
    OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
    SOFTWARE.

-------------------------------------------------------------------------------
5. DEVELOPMENT & TESTING TOOLS (not redistributed)
-------------------------------------------------------------------------------

The test suite uses PHPUnit (BSD 3-Clause License, Copyright (c) 2005-2026,
Sebastian Bergmann), amphp/http-server and amphp/http-client (MIT, amphp) for
the proof of gain and the HTTP parity against a local HTTP server, and Infection (BSD 3-Clause
License, Maks Rafalko) for mutation testing. They are require-dev packages:
Composer does not install them for a consumer of this package, and no code of
theirs is redistributed with it.
