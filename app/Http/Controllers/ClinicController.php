<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Doc_apply;
use App\Models\Doc_clinic;
use App\Models\Doctor;
use App\Models\Medical_report;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\Region;
use App\Models\Secretary;
use App\Models\Specialty;
use App\Models\User;
use App\Models\Worked_time;
use Exception;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpParser\Comment\Doc;
use Tymon\JWTAuth\Facades\JWTAuth;

class ClinicController extends Controller
{
    use ApiResponseTrait;

    public function statistics()
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $patients_count = Patient::query()
            ->join('medical_reports','patients.id','medical_reports.patient_id')
            ->where('medical_reports.clinic_id',$clinic->id)
            ->distinct('medical_reports.patient_id')
            ->count();
        if($patients_count==0){
            $male_ratio = 50;
            $female_ratio = 50;
        }
        else{
            $male_ratio = (int)(Patient::query()
                ->join('medical_reports','patients.id','medical_reports.patient_id')
                ->where('medical_reports.clinic_id',$clinic->id)
                ->where('patients.gender','male')
                ->distinct('medical_reports.patient_id')
                ->count()/$patients_count*100);
            $female_ratio = 100-$male_ratio;
        }
        $profits = Appointment::query()
            ->where('clinic_id',$clinic->id)
            ->where('status','archived')
            ->sum('price');
        $startOfMonth = Carbon::now()->startOfMonth()->subMonth();
        $endOfMonth = Carbon::now()->startOfMonth()->subDay();
        $month_profits = Appointment::query()
            ->where('clinic_id',$clinic->id)
            ->where('status','archived')
            ->whereBetween('updated_at',[$startOfMonth,$endOfMonth])
            ->sum('price');
        $appointments_count = Appointment::query()
            ->where('clinic_id',$clinic->id)
            ->where('status','archived')
            ->count();
        $month_appointment = Appointment::query()
            ->where('clinic_id',$clinic->id)
            ->where('status','archived')
            ->whereBetween('updated_at',[$startOfMonth,$endOfMonth])
            ->count();
        $doctors_count = Doc_clinic::query()
            ->where('clinic_id',$clinic->id)
            ->count();
        $secretaries_count = Secretary::where('clinic_id',$clinic->id)->count();

        $top_doctor = Appointment::query()
            ->join('doctors' , 'doctors.id' , 'appointments.doctor_id')
            -> join('users' , 'doctors.user_id' , 'users.id')
            ->select('doctors.id','users.name', DB::raw('count(*) as appointment_count'))
            ->where(['clinic_id' => $clinic->id, 'status' => 'archived'])
            ->groupBy('doctors.id','users.name')
            ->orderBy('appointment_count' , 'desc')
            //->select('users.name as NAMEEE')
            ->first();

        $data = [
          'patients count' => $patients_count,
          'male ratio' => $male_ratio,
          'female ratio' => $female_ratio,
          'profits' => $profits,
          'last month profits' => $month_profits,
          'appointments count' => $appointments_count,
          'last month appointments' => $month_appointment,
          'doctors count' => $doctors_count,
          'top doctor' => $top_doctor,
          'secretaries count' => $secretaries_count
        ];
        return $this->apiResponse($data,'Statistics returned successfully !',200);
    }

    public function monthlyStatistics(){
        $clinic = JWTAuth::parseToken()->authenticate();
        $year = date('Y');
        $price_per_month = [];
        for ($month = 1; $month <= 12; $month++) {
            $start_date = "$year-$month-01";
            $end_date = date('Y-m-t', strtotime($start_date));
            $monthly_price = Appointment::whereBetween('date', [$start_date, $end_date])->where('clinic_id',$clinic->id)->sum('price');
            $month_name = date('F', strtotime($start_date));
            $price_per_month[$month_name] = $monthly_price;
        }
        return $this->apiResponse($price_per_month,'Data has been got successfully !',200);
    }

    public function test()
    {
        $clinic = JWTAuth::parseToken()->authenticate();

//        $top_doctor = Appointment::query()
//            ->select('doctor_id',count('id'))
//            -> where(['clinic_id' => $clinic->id , 'status' => 'archived'])
//            ->groupBy('doctor_id')->get();
//
//        return $this->apiResponse($top_doctor , 'done' , 200);


        $top_doctor = Appointment::query()
            ->select('doctor_id', DB::raw('count(*) as appointment_count'))
            ->where(['clinic_id' => $clinic->id, 'status' => 'archived'])
            ->groupBy('doctor_id')
            ->orderBy('appointment_count' , 'desc')
            ->first();

        return $this->apiResponse($top_doctor, 'done', 200);
    }

    public function profile()
    {
        $id = $_GET['id'];
        $clinic = Clinic::query()
            ->join('addresses', 'addresses.id', '=', 'clinics.address_id')
            ->join('regions', 'addresses.region_id', '=', 'regions.id')
            ->join('cities', 'regions.city_id', '=', 'cities.id')
            ->select('clinics.id','clinics.name','clinics.phone','clinics.description','clinics.image','clinics.email','clinics.num_of_doctors AS number_of_doctors',DB::raw('clinics.total_of_rate / clinics.num_of_rate AS rate'), 'addresses.address', 'regions.region', 'cities.city')
            ->where('clinics.id',$id)
            ->get();

        if($clinic->isEmpty())
            return $this->apiResponse(null,'some thing wrong !',404);
        return $this->apiResponse($clinic,'ok !',200);
    }

    public function approveDoctor(Request $request)
    {
        $validator = Validator::make($request->all() , [
            'price' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/'],
            'join_date' => ['required','date'],
            'end_date' => 'date',
            'worked_times' => 'array'
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $apply = Doc_apply::find($request->apply_id);
        $doctor = $apply->doctor;
        $clinic = $apply->clinic;
        $doctor_clinic = Doc_clinic::create([
            'price' => $request->price,
            'join_date' => $request->join_date,
            'end_date' => $request->end_date,
        ]);
        $doctor->doctor_clinics()->save($doctor_clinic);
        $clinic->doctor_clinics()->save($doctor_clinic);

        $worked_times = $request->worked_times;
        foreach ($worked_times as $time)
        {
            $times =[];
            for ($i=$time['start'] ; $i< $time['end'] ; $i++)
            {
                array_push($times,strval($i) . ":00");
                array_push($times,strval($i) . ":30");
            }
            //$valuesArray = array_values($times);
           $json_times = json_encode($times);
//            $times2 = json_decode(stripslashes($json_times));
            $worked_time = Worked_time::create([
                'day' => $time['day'],
                'start' => $time['start'],
                'end' => $time['end'],
                'av_times' => $json_times
            ]);
            $doctor->worked_times()->save($worked_time);
            $clinic->worked_times()->save($worked_time);
        }

        $clinic->num_of_doctors++;
        $clinic->save();

        $user = $doctor->user;
        $device_key = $user->device_key;
        $body = "Congrats, your application for our clinic has been approved successfully !";
        $title = $clinic->name;

        Notification::create([
            'title' => $title,
            'body' => $body,
            'user_id' => $user->id
        ]);

        $this->sendNotification($device_key,$body,$title);

        $apply->delete();
        return $this->apiResponse(null,'Done !','200');
    }

    public function getRegions()
    {
        $name = $_GET['name'];
        $regions = Region::query()
            ->join('cities','cities.id','regions.city_id')
            ->where('cities.city',$name)
            ->select('regions.id AS region_id','regions.region')
            ->get();
        if($regions->isEmpty())
        {
            return $this->apiResponse(null,'no results',200);
        }
        return $this->apiResponse($regions,'Regions has been got successfully',200);
    }

    //طلبات انتساب الدكاترة
    public function applications()
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $apps = Doc_apply::query()
            ->join('clinics','clinics.id','doc_applies.clinic_id')
            ->join('doctors','doctors.id','doc_applies.doctor_id')
            ->join('users','users.id','doctors.user_id')
            ->where('clinics.id',$clinic->id)
            ->select('doc_applies.id AS apply_id','users.name AS Doctor Name','users.email AS Doctor Email','doctors.address')
            ->get();
        if ($apps->isEmpty())
        {
            return $this->apiResponse(null,'no applications found !',404);
        }
        return $this->apiResponse($apps,'Applications returned successfully !',200);
    }

    // طلبات الحجز من المرضى التي لم تقبل او ترفض بعد
    public function requests()
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $reqs = Appointment::query()
            ->join('clinics','clinics.id','appointments.clinic_id')
            ->join('doctors','doctors.id','appointments.doctor_id')
            ->join('users','users.id','doctors.user_id')
            ->where('clinics.id',$clinic->id)
            ->where('appointments.status','pending')
            ->orderBy('appointments.date', 'asc')
            ->orderBy('appointments.created_at', 'asc')
            ->select('appointments.id AS id','appointments.full_name AS Patient Name','appointments.age','appointments.description','appointments.date' , 'appointments.time' ,'users.name AS Doctor Name')
            ->get();
        if ($reqs->isEmpty())
        {
            return $this->apiResponse(null,'no requests found !',404);
        }
        return $this->apiResponse($reqs,'Requests returned successfully !',200);
    }

    public function ApprovedAppointments()
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $reqs = Appointment::query()
            ->join('clinics','clinics.id','appointments.clinic_id')
            ->join('doctors','doctors.id','appointments.doctor_id')
            ->join('users','users.id','doctors.user_id')
            ->where('clinics.id',$clinic->id)
            ->where('appointments.status','booked')
            ->orderBy('appointments.date', 'asc')
            ->select('appointments.id','appointments.full_name AS Patient Name','appointments.age','appointments.description','appointments.date' ,'appointments.time','users.name AS Doctor Name')
            ->get();
        if ($reqs->isEmpty())
        {
            return $this->apiResponse(null,'no appointment  found !',404);
        }
        return $this->apiResponse($reqs,'Appointments returned successfully !',200);
    }

    public function doctors(Request $request)
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $doctors = Doctor::query()
            ->join('doc_clinics','doctors.id','doc_clinics.doctor_id')
            ->where('doc_clinics.clinic_id',$clinic->id)
            ->select('doctors.*')
            ->get();
        if ($doctors->isEmpty())
        {
            return $this->apiResponse(null,'no doctors found !',404);
        }
        $data = [] ;
        foreach ($doctors as $doctor)
        {
            $user = $doctor->user;
            $lang = $request->header('lang');
            if($lang == 'en'){
                $specialties = Specialty::query()
                    ->join('spec_docs', 'specialties.id', '=', 'spec_docs.specialty_id')
                    ->where('spec_docs.doctor_id', '=', $doctor->id)
                    ->select('specialty_id','exp_years AS experience_years','nameEn AS specialty name')
                    ->get();
            }
            else {
                $specialties = Specialty::query()
                    ->join('spec_docs', 'specialties.id', '=', 'spec_docs.specialty_id')
                    ->where('spec_docs.doctor_id', '=', $doctor->id)
                    ->select('specialty_id', 'exp_years AS experience_years', 'name AS specialty name')
                    ->get();
            }
            $worked_times = Worked_time::where([
                ['doctor_id', '=', $doctor->id],
                ['clinic_id', '=', $clinic->id]
            ])->select('day','start','end')->get();
            $conract = Doc_clinic::query()
                ->where('doctor_id',$doctor->id)
                ->where('clinic_id',$clinic->id)
                ->first();
            $data[] = [
                'id' => $doctor->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'image' => $user->image,
                'gender' => $user->gender,
                'address' => $doctor->address,
                'price' => $conract->price,
                'join_date' => $conract->join_date,
                'end_date' => $conract->end_date,
                'specialties' => $specialties,
                'worked_times' => $worked_times,
            ];
        }
        return $this->apiResponse($data,'Doctors returned successfully !',200);
    }

    public function patients()
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $patients = Patient::query()
            ->where('clinic_id',$clinic->id)
            ->get();
        if ($patients->isEmpty())
        {
            return $this->apiResponse(null,'no patients found !',404);
        }
        $data = [];
        foreach ($patients as $patient)
        {
            $medical_reports = Medical_report::query()
                ->where('patient_id',$patient->id)
                ->where('clinic_id',$clinic->id)
                ->select('name','specialty','description')
                ->get();
            $data[] = [
                'id' => $patient->id,
                'full_name' => $patient->full_name,
                'mother_name' => $patient->mother_name,
                'age' => $patient->age,
                'gender' => $patient->gender,
                'address' => $patient->address,
                'blood_type' => $patient->blood_type,
                'description' => $patient->description,
                'phone' => $patient->phone,
                'medical_reports' => $medical_reports
            ];
        }
        return $this->apiResponse($data,'Patients returned successfully !',200);
    }

    public function deletePatient()
    {
        $id = $_GET['id'];
        $patient = Patient::find($id);
        if($patient==null)
            return $this->apiResponse(null,'Patient not exist !',404);
        $patient->delete();
        return $this->apiResponse(null,'Patient deleted successfully !',200);
    }

    public function secretaries()
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $secretaries = Secretary::query()
            ->where('clinic_id',$clinic->id)
            ->select('id','name','email')
            ->get();
        if($secretaries->isEmpty())
            return $this->apiResponse(null,'No secretaries found !',404);
        return $this->apiResponse($secretaries,'Secretaries returned successfully !',200);
    }

    public function deleteSec()
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $id = $_GET['id'];
        if (Secretary::where('clinic_id',$clinic->id)->where('id',$id)->delete()==0)
            return $this->apiResponse(null,'Secretary not found !',200);
        return $this->apiResponse(null,'Secretary deleted successfully !',200);
    }

    public function rejectDoctor()
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $doctor_id = $_GET['id'];
        $doctor = Doctor::find($doctor_id);
        $user = $doctor->user;

        $device_key = $user->device_key;
        $body = "Unfortunately, your application for our clinic has been rejected !";
        $title = $clinic->name ;

        Notification::create([
            'title' => $title,
            'body' => $body,
            'user_id' => $user->id
        ]);

        $this->sendNotification($device_key,$body,$title);
        $doc_apply = Doc_apply::where(['clinic_id'=>$clinic->id , 'doctor_id'=>$doctor_id])->delete();
        if ($doc_apply == 0)
            return $this->apiResponse(null , 'Some thing went wrong!' , 400);
        return $this->apiResponse(null , 'Doctor apply has beed rejected' , 200);
    }

    public function rejectAppointment()
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $appointment_id = $_GET['id'];
        $appointment = Appointment::find($appointment_id);
        $clinic = Clinic::find($appointment->clinic_id);
        $device_key = User::query()
            ->where('id',$appointment->user_id)
            ->pluck('device_key')
            ->first();

        $body = "Sorry! your appointment in $appointment->date at $appointment->time has been rejected , try another time please !";
        $title = $clinic->name;

        Notification::create([
            'title' => $title,
            'body' => $body,
            'user_id' => $appointment->user_id
        ]);

        $this->sendNotification($device_key,$body,$title);

        $delete_app = Appointment::where(['id' => $appointment_id , 'clinic_id'=>$clinic->id , 'status' => 'pending'])->delete();
        if ($delete_app == 0)
            return $this->apiResponse(null , 'Some thing went wrong!' , 400);
        return $this->apiResponse(null , 'Appointment has been rejected successfully' , 200);
    }

    public function deleteAppointment()
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $appointment_id = $_GET['id'];
        $delete_app = Appointment::where(['id' => $appointment_id , 'clinic_id'=>$clinic->id , 'status' => 'booked'])->delete();
        if ($delete_app == 0)
            return $this->apiResponse(null , 'Some thing went wrong!' , 400);
        return $this->apiResponse(null , 'Appointment has beed deleted successfully' , 200);
    }

    public function appreveAppointment()
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $appointment_id = $_GET['id'];
        $appointment = Appointment::find($appointment_id);
        $same_time_app = Appointment::query()
            ->where([ 'clinic_id' => $appointment->clinic_id , 'doctor_id' => $appointment->doctor_id ,'date' => $appointment->date , 'time' => $appointment->time , 'status' => 'booked'])->get();

        if (!$same_time_app->isEmpty())
            return $this->apiResponse(null , "This time isn't available, there is another appointment at the same time" , 400);

        $appointment['status'] = 'booked';
        $appointment->save();

        $dateString = $appointment['date'];
        $date = Carbon::createFromFormat('Y-m-d', $dateString);

        $dayOfWeekNumber = $date->dayOfWeek;

        $doc_time = Worked_time::query()
            ->where(['doctor_id' => $appointment->doctor_id , 'clinic_id' => $appointment->clinic_id , 'day'=>$dayOfWeekNumber])->first();


        $times = json_decode($doc_time->av_times, true);
        $timeToDelete = $appointment['time'];

        $index = array_search($timeToDelete, $times);

        if ($index !== false) {
            unset($times[$index]);
            $times = array_values($times);
        }

        $doc_time->av_times = json_encode($times);
        $doc_time->save();

        $clinic = Clinic::find($appointment->clinic_id);
        $device_key = User::query()
            ->where('id',$appointment->user_id)
            ->pluck('device_key')
            ->first();

        $body = "Your appointment in $appointment->date at $appointment->time has been approved successfully !";
        $title = $clinic->name;

        Notification::create([
            'title' => $title,
            'body' => $body,
            'user_id' => $appointment->user_id
        ]);

        $this->sendNotification($device_key,$body,$title);

        return $this->apiResponse(null , "Appointment has been approved successfully!");
    }

    public function sendNotification($device_key,$body,$title)
    {
        try {
            $URL = 'https://fcm.googleapis.com/fcm/send';
            $data = '{
                "to" : "' . $device_key . '",
                "notification" : {
                    "body" : "' . $body . '",
                    "title" : "' . $title . '"
                    },
                }';
            $crl = curl_init();

            $header = array();
            $header[] = 'Content-type: application/json';
            $header[] = 'Authorization: key=' . env('SERVER_API_KEY');
            curl_setopt($crl, CURLOPT_SSL_VERIFYPEER, false);

            curl_setopt($crl, CURLOPT_URL, $URL);
            curl_setopt($crl, CURLOPT_HTTPHEADER, $header);

            curl_setopt($crl, CURLOPT_POST, true);
            curl_setopt($crl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($crl, CURLOPT_RETURNTRANSFER, true);
            curl_exec($crl);
        }
        catch (Exception $e) {
            return $this->apiResponse(null , "NOTIFICATION FAILED !");
        }
    }

    public function archiveApp()
    {
        //$clinic = JWTAuth::parseToken()->authenticate();
        $app_id = $_GET['id'];
        $appointment = Appointment::find($app_id);
        if ($appointment==null)
            return $this->apiResponse(null,'appointment not found !',404);

        $appointment->status = 'archived';
        $appointment->save();

        $dateString = $appointment->date;
        $date = Carbon::createFromFormat('Y-m-d', $dateString);

        $dayOfWeekNumber = $date->dayOfWeek;

        $doc_time = Worked_time::query()
            ->where(['doctor_id' => $appointment->doctor_id , 'clinic_id' => $appointment->clinic_id , 'day'=>$dayOfWeekNumber])->first();


        $times = json_decode($doc_time->av_times, true);

        array_push($times,$appointment->time);
        $times = array_values($times);
        sort($times);

        $doc_time->av_times = json_encode($times);
        $doc_time->save();

        return $this->apiResponse(null , "Appointment has been archived successfully!");
    }

    public function addPatient(Request $request)
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $request->validate([
            'full_name' => 'required|string',
            'mother_name' => 'string',
            'age' => 'int',
            'gender' => 'string',
            'address' => 'string',
            'blood_type' => 'string',
            'description' => 'string',
            'phone' => 'string',
        ]);
        $request['clinic_id'] = $clinic->id;
        Patient::create($request->all());
        return $this->apiResponse(null,'Patient created successfully !',200);
    }

    public function addMedicalReport(Request $request)
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $request->validate([
            'name' => 'required|string',
            'specialty' => 'required|string',
            'description' => 'string',
            'patient_id' => 'required'
        ]);
        $request['clinic_id'] = $clinic->id;
        Medical_report::create($request->all());
        return $this->apiResponse(null,'Medical report created successfully !',200);
    }

    public function archivedAppointments()
    {
        $clinic = JWTAuth::parseToken()->authenticate();
        $reqs = Appointment::query()
            ->join('clinics','clinics.id','appointments.clinic_id')
            ->join('doctors','doctors.id','appointments.doctor_id')
            ->join('users','users.id','doctors.user_id')
            ->where('clinics.id',$clinic->id)
            ->where('appointments.status','archived')
            ->orderBy('appointments.date', 'asc')
            ->select('appointments.id','appointments.full_name AS Patient Name','appointments.age','appointments.description','appointments.date' ,'appointments.time','users.name AS Doctor Name')
            ->get();
        if ($reqs->isEmpty())
        {
            return $this->apiResponse(null,'no appointment  found !',404);
        }
        return $this->apiResponse($reqs,'Appointments returned successfully !',200);
    }

}
