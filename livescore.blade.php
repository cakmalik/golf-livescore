<?php

use Carbon\Carbon;
use App\Models\Game;
use App\Models\GameScore;
use App\Models\Tournament;
use App\Models\LeaderBoard;
use App\Models\GameScoreDetail;
use App\Models\TournamentEvent;
use App\Models\TournamentCategory;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use function Livewire\Volt\{state, layout, title, mount, updated, usesPagination, with, on};

usesPagination(theme: 'simple-bit');

layout('layouts.no-layout-tailwind-base');

title(fn() => 'Livescore | ' . config('app.name'));

state(['game_id', 'game', 'tournament', 'tournament_event', 'categories', 'course', 'game_score_details']);

state(['selected_category', 'category_id', 'current_date', 'selected_day_id', 'selected_day_name' => 'Days']);

state(['is_realtime' => false, 'search' => '', 'currentIndex' => 0]);

state(['is_has_more_page', 'mdl_interval']);

mount(function ($game_id) {
    $this->game = Game::with('event', 'event.days')->findOrFail($game_id);

    $this->categories = TournamentCategory::whereHas('gameScores')
        ->where('tournament_id', $this->game->tournament_id)
        ->get();
    $this->course = $this->game?->course;

    $this->current_date = Carbon::now()->format('l, d M Y');
});

with('leaderboards', function () {
    $scoring = $this->game->tournament_event->scoring_method;
    $scoring = $scoring ?? 'Stroke Play';
    $collection = GameScore::search($this->search)
        ->with(['player.group', 'user', 'game', 'detail', 'user.player', 'player.leaderBoard', 'Details'])
        ->day($this->selected_day_id)
        ->whereHas('user.player', function ($query) {
            $query->whereNot('status', 'DISQUALIFICATION');
        })
        ->where('game_id', $this->game->id)
        ->category($this->selected_category['id'] ?? null)
        ->get()
        ->map(function ($item) use ($scoring) {
            $thru = $item->Details->where('score', '>', 0)->count();
            $gross = $item->Details->sum('score');

            // NOTE: for temp. next update terus hcp stlh input score
            if ($scoring != 'System 36') {
                $hcp = $item->player?->leaderBoard?->handicap;
            } else {
                $hcp = $thru == 18 ? intval(36 - $item->total_s36_point) : 0;
            }
            $net = $thru == 18 ? $gross - $hcp : '-';
            return [
                'id' => $item->id,
                'name' => $item->player->member_name,
                'pair_name' => $item->player?->group?->name,
                'pair_id' => $item->player->group->id,
                'thru' => $thru == 18 ? 'F' : $thru,
                'gross' => $gross,
                'net' => $net,
                'hcp' => $hcp,
                'details' => $item->Details->toArray(),
                'point' => $item->total_s36_point,
                'score' => $gross,
            ];
        });

    if ($scoring == 'System 36') {
        $sortedCollection = $collection->sortBy('net')->sortByDesc('thru');
    } else {
        $sortedCollection = $collection->sortBy('gross')->sortByDesc('thru');
    }

    $perPage = 10;
    $page = Paginator::resolveCurrentPage('page');

    $currentPageItems = $sortedCollection->slice(($page - 1) * $perPage, $perPage)->values();

    $paginated = new LengthAwarePaginator($currentPageItems, $sortedCollection->count(), $perPage, $page, [
        'path' => Paginator::resolveCurrentPath(),
    ]);

    $this->is_has_more_page = $paginated->hasMorePages();

    return $paginated;
});

$toggleIsRealtime = function () {
    $this->is_realtime = !$this->is_realtime;
};

$setSelectedCategory = function ($category = null) {
    if ($category == null) {
        $this->selected_category = null;
        return;
    }
    $this->selected_category = $category;
    $this->resetPage();
};

on([
    'eventName' => function () {
        if ($this->is_realtime) {
            if ($this->is_has_more_page) {
                $this->nextPage();
            } else {
                $this->nextCategory();
            }
        }
    },
    'menu:changeInterval' => function () {
        $this->mdl_interval = true;
    },
]);

$nextCategory = function () {
    $this->resetPage();
    // Jika selected_category null, pilih kategori pertama
    if ($this->selected_category === null) {
        if ($this->categories->isNotEmpty()) {
            $this->setSelectedCategory($this->categories[$this->currentIndex]);
        }
    } else {
        // Increment index untuk kategori berikutnya
        $this->currentIndex++;

        // Jika sudah mencapai akhir kategori, reset ke null dan index
        if ($this->currentIndex >= $this->categories->count()) {
            $this->currentIndex = 0;
            $this->selected_category = null; // Kembali ke null
        } else {
            // Set kategori berikutnya
            $this->setSelectedCategory($this->categories[$this->currentIndex]);
        }
    }
};
updated(['search' => fn() => $this->resetPage()]);
// updated(['selected_day_id' => fn() => dd($this->selected_day_id)]);
?>
<div class="relative w-full h-screen p-5 text-sm sm:px-10 md:px-20 lg:px-10 lg:py-10 font-sora md:text-medium"
    x-data="settings()">
    <!-- Video background -->
    <video autoplay muted loop playsinline class="fixed top-0 left-0 object-cover w-full min-h-screen m-0">
        <source src="{{ asset('images/screensaver.mp4') }}" type="video/mp4">
        Your browser does not support the video tag.
    </video>

    <!-- Content on top of video -->
    <div class="relative z-10 flex flex-col w-full" x-data="{ ...countdown(), ...clock(), openModal: false }" x-init="startCountdown();
    startClock()">

        <!-- Modal -->
        <div x-cloak>
            <div class="fixed inset-0 z-[999999999999999999999] flex items-center justify-center bg-black bg-opacity-50"
                x-show="openModal" x-transition @click.outside="openModal = false">
                <!-- Modal Content -->
                <div class="w-1/3 p-4 bg-white rounded-lg shadow-lg">
                    <div class="mb-4 text-lg font-semibold">Select Day</div>
                    <div class="space-y-2 text-center text-black">
                        <button class="px-4 py-2 cursor-pointer hover:bg-gray-100"
                            x-on:click="$wire.set('selected_day_id',null); $wire.set('selected_day_name', 'Days'); $nextTick(() => openModal = false)">
                            All</button>
                        @foreach ($game->event?->days as $i)
                            <button class="px-4 py-2 cursor-pointer hover:bg-gray-100"
                                x-on:click="$wire.set('selected_day_id',{{ $i->id }}); $wire.set('selected_day_name', '{{ $i->name }}'); $nextTick(() => openModal = false)">
                                {{ $i->name }}</button>
                        @endforeach
                    </div>

                    <!-- Close Button -->
                    {{-- <div class="mt-4 text-right">
                        <button class="px-4 py-2 text-white bg-gray-500 rounded hover:bg-gray-600"
                            x-on:click="openModal = false">
                            Close
                        </button>
                    </div> --}}
                </div>
            </div>
        </div>


        {{-- Header --}}
        <div
            class="relative p-3 overflow-hidden border border-white header bg-white/80 backdrop-filter backdrop-blur-lg rounded-2xl sm:p-5">
            <div
                class="hidden sm:block absolute w-1/2 h-full rounded-t-[100px] bg-red-300 right-0 top-0 translate-x-1/2 bg-gradient-to-b from-[#d1d1d1] to-[#ededed] ">
            </div>
            <div class="flex items-end justify-between">
                <div class="flex flex-wrap items-center justify-center gap-6 text-center lg:justify-start">
                    <div class="flex items-center gap-3">
                        <img src="{{ asset('images/logo_new.png') }}" class="h-10 sm:h-14">
                        {{-- <img src="{{ asset('images/logo_livescore_2.png') }}" class="h-10 sm:h-14"> --}}
                    </div>
                    <div class="z-50 flex flex-col items-center lg:items-start">
                        <h1 class="font-bold uppercase md:text-2xl">{{ $game->tournament_name }}</h1>
                        <h3 class="font-light uppercase">{{ $game->course?->name }}</h3>

                        <h3 class="block font-light uppercase lg:hidden">
                            {{ strtoupper(\Carbon\Carbon::parse($game->event->start_date)->format('l, d M Y')) }}
                        </h3>
                    </div>
                </div>
                <div class="right-0 z-50 flex-col items-end hidden font-semibold uppercase xl:flex">
                    <span class="text-xl ">{{ $current_date }}</span>
                    <div class="text-2xl font-semibold"><span x-text="hours"></span>:<span
                            x-text="minutes"></span>:<span x-text="seconds_2"></span>
                    </div>
                </div>
            </div>
        </div>

        {{-- @if ($is_realtime)
            <span class="fixed z-50 hidden font-semibold xl:block right-3 top-3 md:text-4xl text-white/30"
                x-text="seconds"></span>
        @endif --}}


        {{-- body --}}
        <div
            class="flex-col flex-1 w-full mt-4 overflow-hidden border border-2 bg-white/80 backdrop-filter backdrop-blur-md rounded-2xl ">
            {{-- header --}}
            <div class="header w-full bg-kraton p-2.5 flex flex-wrap gap-3 justify-between items-center">
                <div class="flex w-full gap-2 overflow-auto md:w-1/2">
                    @if ($categories)
                        <x-b.button-livescore-kraton :active="$selected_category == null" wire:click='setSelectedCategory(null)'
                            label="ALL" />
                        @foreach ($categories as $item)
                            <x-b.button-livescore-kraton :active="$item?->id == ($selected_category['id'] ?? null)"
                                wire:click='setSelectedCategory({{ $item }})' :label="$item->name" />
                        @endforeach

                    @endif
                </div>
                <div class="flex w-full gap-3 md:w-1/3">
                    <input type="text" placeholder="Search Name..." wire:model.live.debounce.500ms='search'
                        class="w-full px-2 py-1 sm:p-1.5 sm:px-4 sm:py-2 rounded-lg sm:rounded-xl border border-white border-opacity-50 placeholder:text-white border-[#9d9898] focus:outline-none focus:border-none focus:ring-0 text-white bg-white/20 text-sm">
                    <button @class([
                        'p-2 px-3 rounded-xl  transition ease-in-out duration-300 flex items-center justify-center',
                        'bg-green-500 text-white' => $is_realtime,
                        'bg-gray-200 text-black' => !$is_realtime,
                    ]) @click="$wire.toggleIsRealtime">
                        @if ($is_realtime)
                            <i class="ph-fill ph-pause "></i>
                        @else
                            <i class="ph-fill ph-play "></i>
                        @endif
                    </button>
                </div>
            </div>

            {{-- table --}}
            <div class="h-full overflow-x-auto text-sm md:text-medium">
                <table class="min-w-full border-collapse table-fixed ">
                    <thead class="bg-gradient-to-b from-kraton-a to-kraton-b">
                        <tr class="text-white xl:text-lg">
                            <th rowspan="2" class="w-8 p-0.5 px-1 sm:p-2 sticky-col bg-kraton"
                                style="background-color: #239952"></th>
                            <th rowspan="2" class="text-start p-0.5 px-1 sm:p-2 w-64 sticky-col bg-kraton"
                                style="background-color: #239952">
                                @if ($game->event?->days)
                                    <!-- Trigger Button -->
                                    <div class="cursor-pointer" x-on:click="openModal = true">
                                        {{ $selected_day_name }} <i class="ph ph-caret-down"></i>
                                    </div>
                                @else
                                    NAME
                                @endif
                            </th>
                            <th rowspan="2" class="p-0.5 px-1 sm:p-2">Thru</th>
                            {{-- <th rowspan="2" class="p-0.5 px-1 sm:p-2">Point</th> --}}
                            <th rowspan="2" class="p-0.5 px-1 sm:p-2">Total</th>
                            <th rowspan="2" class="p-0.5 px-1 sm:p-2">HCP</th>
                            <th rowspan="2" class="p-0.5 px-1 sm:p-2">NETT</th>
                            <th class="p-0.5 px-1 sm:p-2 font-light ">HOLE</th>

                            @if (!empty($leaderboards) && !empty($leaderboards[0]['score_details']))
                                @foreach ($leaderboards[0]['score_details'] as $item)
                                    <th class="p-0.5 px-1 sm:p-2">{{ $item->number ?? 'N/A' }}</th>
                                    @if ($loop->index == 8)
                                        <th class="p-0.5 px-1 sm:p-2 font-bold">OUT</th>
                                    @endif
                                @endforeach
                            @else
                                @for ($i = 1; $i <= 18; $i++)
                                    <th class="p-0.5 px-1 sm:p-2 font-light ">
                                        {{ $i }}</th>
                                    @if ($i == 9)
                                        <th class="p-0.5 px-1 sm:p-2 font-bold">OUT</th>
                                    @endif
                                @endfor
                            @endif

                            <th class="p-0.5 px-1 sm:p-2 font-bold">IN</th>
                        </tr>
                        <tr class="text-white xl:text-lg">
                            <th class="p-0.5 px-1 sm:p-2 font-light">PAR</th>

                            @if (!empty($leaderboards) && !empty($leaderboards[0]['score_details']))
                                @php
                                    $outParTotal = 0;
                                    $inParTotal = 0;
                                @endphp
                                @foreach ($leaderboards[0]['score_details'] as $item)
                                    <th class="p-0.5 px-1 sm:p-2">{{ $item->par ?? 'N/A' }}</th>
                                    @if ($loop->index < 9)
                                        @php $outParTotal += $item->par; @endphp
                                    @else
                                        @php $inParTotal += $item->par; @endphp
                                    @endif
                                    @if ($loop->index == 8)
                                        <th class="p-0.5 px-1 sm:p-2 font-bold text-lg">{{ $outParTotal }}</th>
                                    @endif
                                @endforeach
                            @else
                                @php
                                    $outParTotal = 0;
                                    $inParTotal = 0;
                                @endphp
                                @foreach ($course->Pars as $i => $par)
                                    <th class="p-0.5 px-1 sm:p-2">{{ $par->par ?? 'N/A' }}</th>
                                    @if ($i < 9)
                                        @php $outParTotal += $par->par; @endphp
                                    @else
                                        @php $inParTotal += $par->par; @endphp
                                    @endif
                                    @if ($i == 8)
                                        <th class="p-0.5 px-1 sm:p-2 font-bold text-lg">{{ $outParTotal }}</th>
                                    @endif
                                @endforeach
                            @endif
                            <th class="p-0.5 px-1 sm:p-2 font-bold text-lg">{{ $inParTotal }}</th>
                        </tr>
                    </thead>

                    <tbody class="text-black " wire:poll.66s>
                        @if (!empty($leaderboards))
                            @foreach ($leaderboards as $key => $value)
                                <tr class="w-full border-b hover:bg-white/50 border-gray">
                                    <td class="px-4 text-center bg-white sticky-col" style="background-color: #fff;">
                                        {{ $leaderboards->firstItem() + $key }}</td>
                                    <td class="text-sm font-semibold truncate bg-white max-w-32 sm:max-w-56 lg:max-w-72 text-start whitespace-nowrap font-inter sm:text-lg sticky-col"
                                        style=" background-color: #fff; white-space: nowrap; text-overflow:ellipsis; overflow: hidden; ">
                                        {{ $value['name'] }}
                                    </td>


                                    <td class="text-center p-1.5 xl:p-3">
                                        <div class="flex items-center justify-center w-full">
                                            <x-scoring-color :value="$value['thru']" :is_hole='false' bg="white"
                                                :point="true" />
                                        </div>
                                    </td>
                                    <td class="text-center p-1.5 xl:p-3">
                                        <div class="flex items-center justify-center w-full">
                                            <x-scoring-color :value="$value['gross']" :is_hole='false' bg="green"
                                                :point="true" />
                                        </div>
                                    </td>
                                    <td class="text-center p-1.5 xl:p-3">
                                        <div class="flex items-center justify-center w-full">
                                            <x-scoring-color :value="$value['hcp']" :is_hole='false' bg="white"
                                                :point="true" />
                                        </div>
                                    </td>
                                    <td class="text-center p-1.5 xl:p-3">
                                        <div class="flex items-center justify-center w-full">
                                            <x-scoring-color :value="$value['net']" :is_hole='false' bg="red"
                                                :point="true" />
                                        </div>
                                    </td>
                                    <td></td>

                                    @php
                                        $front9Total = 0;
                                        $back9Total = 0;
                                    @endphp
                                    @foreach ($value['details'] as $i => $score)
                                        {{-- NOTE:TRY DEBUGING --}}
                                        {{-- @php
                                            if (is_object($score)) {
                                                $scoreValue = $score->score;
                                                $scorePar = $score->par;
                                                $scoreNumber = $score->number;
                                            } elseif (is_array($score)) {
                                                $scoreValue = $score['score'];
                                                $scorePar = $score['par'];
                                                $scoreNumber = $score['number'];
                                            }
                                        @endphp --}}
                                        {{-- NOTE:TRY DEBUGING --}}
                                        {{-- @php
                                            try {
                                                $holeOut = $score->number >= 1 && $score->number <= 9;
                                                $holeIn = $score->number >= 10 && $score->number <= 18;
                                            } catch (Exception $e) {
                                                dd($e->getMessage()); // Akan mengeluarkan pesan error saat rendering
                                            }
                                        @endphp --}}

                                        {{-- Menambahkan toArray() jika $value['details'] adalah koleksi Eloquent --}}
                                        @php
                                            $holeOut = $score['number'] >= 1 && $score['number'] <= 9;
                                            $holeIn = $score['number'] >= 10 && $score['number'] <= 18;
                                        @endphp
                                        @if ($i < 9)
                                            @php $front9Total += $score['score']; @endphp
                                        @elseif ($i >= 9)
                                            @php $back9Total += $score['score']; @endphp
                                        @endif

                                        <td class="inset-0 p-0 py-1 text-center xl:py-2">
                                            @if ($score['score'] == 0)
                                                <div
                                                    class="w-full h-full p-1.5 flex items-center justify-center
                                              @if ($holeOut) bg-white @endif
                                              @if ($holeIn) bg-dark @endif
                                              @if ($score['number'] == 1 || $score['number'] == 10) rounded-l-3xl @endif
                                              @if ($score['number'] == 9 || $score['number'] == 18) rounded-r-3xl @endif">
                                                    <x-scoring-color value="none" :is_hole='false'
                                                        bg="dark" />
                                                </div>
                                            @else
                                                @php
                                                    $colorData = \App\Helpers\Universal::getScoringColor(
                                                        $score['par'], // Akses melalui array
                                                        $score['score'],
                                                    );
                                                @endphp
                                                <div
                                                    class="w-full h-full p-1.5 flex items-center justify-center
                                              @if ($holeOut) bg-white @endif
                                              @if ($holeIn) bg-dark @endif
                                              @if ($score['number'] == 1 || $score['number'] == 10) rounded-l-3xl @endif
                                              @if ($score['number'] == 9 || $score['number'] == 18) rounded-r-3xl @endif">
                                                    <x-kraton-scoring-color value="{{ $score['score'] }}"
                                                        :color="$colorData[0]" :line="$colorData[1]" />
                                                </div>
                                            @endif
                                        </td>

                                        @if ($i == 8)
                                            <td class="text-center p-1.5 xl:p-3">
                                                <div class="flex items-center justify-center w-full">
                                                    <x-scoring-color :value="$front9Total" :is_hole='false'
                                                        bg="green" :bold="true" :point="true" />
                                                </div>
                                            </td>
                                        @endif
                                    @endforeach

                                    <td class="text-center p-1.5 xl:p-3">
                                        <div class="flex items-center justify-center w-full">
                                            <x-scoring-color :value="$back9Total" :is_hole='false' bg="green"
                                                :bold="true" :point="true" />
                                        </div>
                                    </td>

                                </tr>
                            @endforeach
                        @endif
                    </tbody>
                </table>
            </div>


        </div>
        <x-b.custom-pagination :data="$leaderboards" />
        <x-ui.sponsor />
    </div>

    <x-dialog wire:model='mdl_interval' title="Set Custom Interval">
        <div class="flex gap-3">
            <input type="text"
                class="w-full p-3 rounded-lg border  border-opacity-50 placeholder:text-white border-[#9d9898] focus:outline-none focus:border-[#9d9898] text-black bg-white/20 text-sm text-center text-xl"
                wire:model.live.debounce.500ms='seconds' x-on:click="$el.select()">
            <button
                class="flex items-center justify-center p-2 px-6 text-white transition duration-300 ease-in-out bg-green-500 rounded-xl">
                <i class="ph ph-check"></i>
            </button>
        </div>
    </x-dialog>


    <livewire:menu-ctx />


    {{ $leaderboards->links() }}

    <script>
        function countdown() {
            return {
                // s: localStorage.getItem('seconds') ? parseInt(localStorage.getItem('seconds')) : 10,
                seconds: 15,
                interval: 2,
                startCountdown() {
                    this.interval = setInterval(() => {
                        if (this.seconds > 0) {
                            this.seconds--;
                        } else {
                            this.performAction();
                            this.seconds = 10
                        }
                    }, 1000);
                },
                performAction() {
                    window.Livewire.dispatch('eventName');
                }
            };
        }

        function clock() {
            return {
                hours: '',
                minutes: '',
                seconds_2: '',
                startClock() {
                    this.updateTime();
                    setInterval(() => {
                        this.updateTime();
                    }, 1000);
                },
                updateTime() {
                    let currentTime = new Date();
                    this.hours = String(currentTime.getHours()).padStart(2, '0');
                    this.minutes = String(currentTime.getMinutes()).padStart(2, '0');
                    this.seconds_2 = String(currentTime.getSeconds()).padStart(2, '0');
                }
            }
        }
    </script>

    {{-- <livewire:component.change-colors /> --}}
</div>
