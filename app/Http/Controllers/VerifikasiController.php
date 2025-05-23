<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\NilaiKPI;
use App\Models\Bidang;
use App\Models\Notifikasi;
use App\Models\AktivitasLog;
use App\Models\TahunPenilaian;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class VerifikasiController extends Controller
{
    /**
     * Constructor - hanya Master Admin yang bisa mengakses
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            if (Auth::user()->role !== 'asisten_manager') {
                return redirect()->route('dashboard')->with('error', 'Anda tidak memiliki akses ke fitur ini.');
            }
            return $next($request);
        });
    }

    /**
     * Menampilkan daftar nilai KPI yang belum diverifikasi
     */
    public function index(Request $request)
    {
        $tahun = $request->tahun ?? date('Y');
        $bulan = $request->bulan ?? date('m');
        $bidangId = $request->bidang_id;

        $query = NilaiKPI::with(['indikator.bidang', 'indikator.pilar', 'user'])
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->where('diverifikasi', false)
            ->orderBy('created_at', 'desc');

        if ($bidangId) {
            $query->whereHas('indikator', function($q) use ($bidangId) {
                $q->where('bidang_id', $bidangId);
            });
        }

        $nilaiKPIs = $query->paginate(20);
        $bidangs = Bidang::orderBy('nama')->get();

        // Cek apakah periode ini terkunci
        $tahunPenilaian = TahunPenilaian::where('tahun', $tahun)
            ->where('is_aktif', true)
            ->first();

        $isPeriodeLocked = $tahunPenilaian ? $tahunPenilaian->is_locked : false;

        return view('verifikasi.index', compact('nilaiKPIs', 'bidangs', 'tahun', 'bulan', 'bidangId', 'isPeriodeLocked'));
    }

    /**
     * Menampilkan detail nilai KPI yang akan diverifikasi
     */
    public function show($id)
    {
        $nilaiKPI = NilaiKPI::with(['indikator.bidang', 'indikator.pilar', 'user'])
            ->findOrFail($id);

        // Cek apakah periode ini terkunci
        $tahunPenilaian = TahunPenilaian::where('tahun', $nilaiKPI->tahun)
            ->where('is_aktif', true)
            ->first();

        $isPeriodeLocked = $tahunPenilaian ? $tahunPenilaian->is_locked : false;

        return view('verifikasi.show', compact('nilaiKPI', 'isPeriodeLocked'));
    }

    /**
     * Memverifikasi nilai KPI
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $nilaiKPI = NilaiKPI::with('indikator')->findOrFail($id);

        // Jika sudah diverifikasi, tidak perlu diproses lagi
        if ($nilaiKPI->diverifikasi) {
            return redirect()->route('verifikasi.index')->with('info', 'Nilai KPI ini sudah diverifikasi sebelumnya.');
        }

        // Cek apakah periode ini terkunci
        $tahunPenilaian = TahunPenilaian::where('tahun', $nilaiKPI->tahun)
            ->where('is_aktif', true)
            ->first();

        if ($tahunPenilaian && $tahunPenilaian->is_locked) {
            return redirect()->route('verifikasi.index')
                ->with('error', 'Periode penilaian tahun ' . $nilaiKPI->tahun . ' telah dikunci. Verifikasi tidak dapat dilakukan.');
        }

        // Verifikasi nilai KPI
        $nilaiKPI->update([
            'diverifikasi' => true,
            'verifikasi_oleh' => $user->id,
            'verifikasi_pada' => Carbon::now(),
        ]);

        // Log aktivitas
        AktivitasLog::log(
            $user,
            'verify',
            'Memverifikasi nilai KPI ' . $nilaiKPI->indikator->kode . ' - ' . $nilaiKPI->indikator->nama,
            [
                'indikator_id' => $nilaiKPI->indikator_id,
                'nilai' => $nilaiKPI->nilai,
                'tahun' => $nilaiKPI->tahun,
                'bulan' => $nilaiKPI->bulan,
            ],
            $request->ip(),
            $request->userAgent()
        );

        // Kirim notifikasi ke user yang menginput
        Notifikasi::create([
            'user_id' => $nilaiKPI->user_id,
            'judul' => 'KPI Terverifikasi',
            'pesan' => 'Nilai KPI ' . $nilaiKPI->indikator->kode . ' - ' . $nilaiKPI->indikator->nama . ' telah diverifikasi oleh ' . $user->name,
            'jenis' => 'success',
            'dibaca' => false,
            'url' => route('kpi.show', $nilaiKPI->indikator_id),
        ]);

        return redirect()->route('verifikasi.index')->with('success', 'Nilai KPI berhasil diverifikasi.');
    }

    /**
     * Menolak nilai KPI
     */
    public function destroy(Request $request, $id)
    {
        $user = Auth::user();
        $nilaiKPI = NilaiKPI::with('indikator')->findOrFail($id);

        $request->validate([
            'alasan_penolakan' => 'required|string',
        ]);

        // Cek apakah periode ini terkunci
        $tahunPenilaian = TahunPenilaian::where('tahun', $nilaiKPI->tahun)
            ->where('is_aktif', true)
            ->first();

        if ($tahunPenilaian && $tahunPenilaian->is_locked) {
            return redirect()->route('verifikasi.index')
                ->with('error', 'Periode penilaian tahun ' . $nilaiKPI->tahun . ' telah dikunci. Penolakan tidak dapat dilakukan.');
        }

        // Catat informasi indikator sebelum dihapus
        $indikatorId = $nilaiKPI->indikator_id;
        $indikatorKode = $nilaiKPI->indikator->kode;
        $indikatorNama = $nilaiKPI->indikator->nama;
        $userId = $nilaiKPI->user_id;
        $nilai = $nilaiKPI->nilai;
        $tahun = $nilaiKPI->tahun;
        $bulan = $nilaiKPI->bulan;

        // Hapus nilai KPI yang ditolak
        $nilaiKPI->delete();

        // Log aktivitas
        AktivitasLog::log(
            $user,
            'delete',
            'Menolak nilai KPI ' . $indikatorKode . ' - ' . $indikatorNama,
            [
                'indikator_id' => $indikatorId,
                'nilai' => $nilai,
                'tahun' => $tahun,
                'bulan' => $bulan,
                'alasan' => $request->alasan_penolakan,
            ],
            $request->ip(),
            $request->userAgent()
        );

        // Kirim notifikasi ke user yang menginput
        Notifikasi::create([
            'user_id' => $userId,
            'judul' => 'KPI Ditolak',
            'pesan' => 'Nilai KPI ' . $indikatorKode . ' - ' . $indikatorNama . ' ditolak oleh ' . $user->name . '. Alasan: ' . $request->alasan_penolakan,
            'jenis' => 'danger',
            'dibaca' => false,
            'url' => route('kpi.create', ['indikator_id' => $indikatorId]),
        ]);

        return redirect()->route('verifikasi.index')->with('success', 'Nilai KPI berhasil ditolak.');
    }

    /**
     * Verifikasi massal beberapa nilai KPI sekaligus
     */
    public function verifikasiMassal(Request $request)
    {
        $request->validate([
            'nilai_ids' => 'required|array',
            'nilai_ids.*' => 'exists:nilai_kpi,id',
        ]);

        $user = Auth::user();
        $nilaiKPIs = NilaiKPI::with('indikator')->whereIn('id', $request->nilai_ids)->get();

        // Cek apakah ada nilai KPI dari periode yang terkunci
        $tahunList = $nilaiKPIs->pluck('tahun')->unique();
        $lockedPeriods = TahunPenilaian::whereIn('tahun', $tahunList)
            ->where('is_locked', true)
            ->get();

        if ($lockedPeriods->count() > 0) {
            $lockedYears = $lockedPeriods->pluck('tahun')->implode(', ');
            return redirect()->route('verifikasi.index')
                ->with('error', 'Periode penilaian tahun ' . $lockedYears . ' telah dikunci. Verifikasi massal tidak dapat dilakukan.');
        }

        $count = 0;

        foreach ($nilaiKPIs as $nilaiKPI) {
            // Lewati yang sudah diverifikasi
            if ($nilaiKPI->diverifikasi) continue;

            $nilaiKPI->update([
                'diverifikasi' => true,
                'verifikasi_oleh' => $user->id,
                'verifikasi_pada' => Carbon::now(),
            ]);

            // Log aktivitas
            AktivitasLog::log(
                $user,
                'verify',
                'Memverifikasi nilai KPI ' . $nilaiKPI->indikator->kode . ' - ' . $nilaiKPI->indikator->nama,
                [
                    'indikator_id' => $nilaiKPI->indikator_id,
                    'nilai' => $nilaiKPI->nilai,
                    'tahun' => $nilaiKPI->tahun,
                    'bulan' => $nilaiKPI->bulan,
                ],
                $request->ip(),
                $request->userAgent()
            );

            // Kirim notifikasi ke user yang menginput
            Notifikasi::create([
                'user_id' => $nilaiKPI->user_id,
                'judul' => 'KPI Terverifikasi',
                'pesan' => 'Nilai KPI ' . $nilaiKPI->indikator->kode . ' - ' . $nilaiKPI->indikator->nama . ' telah diverifikasi oleh ' . $user->name,
                'jenis' => 'success',
                'dibaca' => false,
                'url' => route('kpi.show', $nilaiKPI->indikator_id),
            ]);

            $count++;
        }

        return redirect()->route('verifikasi.index')
            ->with('success', 'Berhasil memverifikasi ' . $count . ' nilai KPI.');
    }
}
